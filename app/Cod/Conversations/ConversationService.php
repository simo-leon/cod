<?php

namespace App\Cod\Conversations;

use App\Cod\Automation\AutomationSettings;
use App\Cod\Orders\OrderServiceInterface;
use App\Models\CodConversation;
use App\Models\CodOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The product-inquiry conversation flow (state, not messages).
 *
 * A customer who asks about a product on WhatsApp has no order yet.
 * This service tracks what was collected for their conversation
 * (CodConversation): the matched product, the four answers (name,
 * city, address, phone), and the flow state. It asks the questions
 * one by one (from the admin settings), shows a summary before the
 * confirmation, and creates the real order once the customer
 * confirms. It never sends a WhatsApp message itself — the caller
 * (WhatsAppService / the AI agent) sends the returned texts.
 *
 * Order creation: the order is recorded through the same API-import
 * path the COD System already uses for external orders
 * (OrderService::importFromPayload, source 'whatsapp'), with the
 * customer already confirmed — so no confirmer assignment and no
 * confirmation message are triggered for it. A real store order is
 * NOT created here: there is no programmatic store-order path in the
 * codebase, and the COD record is the order of record, exactly like
 * API-imported external orders. That also means automatic delivery
 * sending (which needs a linked store order) is skipped for these
 * orders until a store order exists.
 */
class ConversationService implements ConversationServiceInterface
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected AutomationSettings $automation,
    ) {}

    /**
     * The conversation for one normalized phone number, created when
     * there is none yet. The caller passes the already-normalized
     * phone (see App\Cod\Support\PhoneNumber).
     *
     * ai_enabled is set explicitly here: a freshly created model does
     * not load the database default into memory, so relying on the
     * column default would leave ai_enabled null — and isEscalated()
     * would read that as "handed to a human" for every new
     * conversation.
     */
    public function findOrCreateByPhone(string $phone): CodConversation
    {
        return CodConversation::firstOrCreate(
            ['phone' => $phone],
            [
                'state' => CodConversation::STATE_COLLECTING_INFO,
                'ai_enabled' => true,
            ],
        );
    }

    /**
     * Start the collection flow for one matched product: record the
     * product on the conversation and return the first question.
     * Answers collected earlier in the same conversation are kept
     * (same customer, same address): when everything is already
     * known, the summary is returned right away instead of asking
     * again.
     */
    public function startProductInquiry(CodConversation $conversation, array $product): string
    {
        $collected = $conversation->collected ?? [];
        $collected['product'] = [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'price' => (float) $product['price'],
        ];
        $collected['answers'] = $collected['answers'] ?? [];

        $conversation->product_sku = (string) $product['sku'];
        $conversation->collected = $collected;
        $conversation->state = CodConversation::STATE_COLLECTING_INFO;
        $conversation->save();

        return $this->questionFor($conversation);
    }

    /**
     * The question for the next missing field, or the summary when
     * everything is collected. The question texts come from the admin
     * settings, in field order (name, city, address, phone).
     */
    public function questionFor(CodConversation $conversation): string
    {
        $field = $conversation->nextField();

        if ($field === null) {
            return $this->summaryText($conversation);
        }

        $questions = $this->automation->questions();
        $index = array_search($field, CodConversation::COLLECT_FIELDS, true);

        return $questions[$index] ?? $questions[0];
    }

    /**
     * Record one collected answer and return the next question (or the
     * summary when that was the last field). Moves the conversation to
     * `awaiting_confirmation` once everything is collected.
     */
    public function recordAnswer(CodConversation $conversation, string $field, string $value): string
    {
        if (! in_array($field, CodConversation::COLLECT_FIELDS, true)) {
            return $this->questionFor($conversation);
        }

        $collected = $conversation->collected ?? [];
        $collected['answers'][$field] = trim($value);
        $conversation->collected = $collected;

        if ($conversation->nextField() === null) {
            $conversation->state = CodConversation::STATE_AWAITING_CONFIRMATION;
        }

        $conversation->save();

        return $this->questionFor($conversation);
    }

    /**
     * The summary shown before confirmation: product, price and the
     * four collected answers, then the confirmation question.
     */
    public function summaryText(CodConversation $conversation): string
    {
        $product = $conversation->product();
        $answers = $conversation->answers();

        $lines = ['هادو هوما المعلومات ديال الطلب ديالك:'];

        if ($product) {
            $lines[] = 'المنتج: '.($product['name'] ?? '').' — '.number_format((float) ($product['price'] ?? 0), 2).' DH';
        }

        $labels = [
            'name' => 'الاسم',
            'city' => 'المدينة',
            'address' => 'العنوان',
            'phone' => 'التيليفون',
        ];

        foreach (CodConversation::COLLECT_FIELDS as $field) {
            $lines[] = $labels[$field].': '.($answers[$field] ?? '—');
        }

        $lines[] = 'واش نأكدو الطلب؟';

        return implode("\n", $lines);
    }

    /**
     * Create the real COD order for a confirmed product inquiry.
     *
     * Uses the API-import path with source 'whatsapp' and the order
     * already confirmed, so no confirmation message is sent for it.
     * When automatic order creation is off in the admin, nothing is
     * created and null is returned: the conversation stays in
     * `awaiting_confirmation` for a human to create the order.
     * Never throws: a failure returns null and is logged.
     */
    public function createOrder(CodConversation $conversation): ?CodOrder
    {
        if (! $this->automation->autoCreateOrder()) {
            return null;
        }

        $product = $conversation->product();
        $answers = $conversation->answers();

        if ($product === null || $conversation->nextField() !== null) {
            return null;
        }

        $price = (float) ($product['price'] ?? 0);

        // M29: retry with a fresh external id if the import fails —
        // a unique-id collision must not lose the customer's order.
        $order = null;
        $attempts = 0;

        while ($order === null && $attempts < 3) {
            $attempts++;

            try {
                $order = $this->orders->importFromPayload([
                    'external_id' => $this->newExternalId(),
                    'payment_method' => 'cashondelivery',
                    'customer_name' => $answers['name'] ?? null,
                    'customer_phone' => $answers['phone'] ?? $conversation->phone,
                    'city' => $answers['city'] ?? null,
                    'address' => $answers['address'] ?? null,
                    'currency_code' => 'MAD',
                    'grand_total' => $price,
                    'items_count' => 1,
                    'items' => [
                        [
                            'name' => $product['name'],
                            'sku' => $product['sku'],
                            'qty' => 1,
                            'price' => $price,
                        ],
                    ],
                ], CodOrder::SOURCE_WHATSAPP, true);
            } catch (\Throwable $e) {
                Log::warning('COD conversations: failed to create order from product inquiry', [
                    'cod_conversation_id' => $conversation->id,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($order === null) {
            return null;
        }

        if ($order) {
            $conversation->cod_order_id = $order->id;
            $conversation->state = CodConversation::STATE_CONFIRMED;
            $conversation->save();
        }

        return $order;
    }

    /**
     * Mark the conversation canceled (the customer refused).
     */
    public function cancel(CodConversation $conversation): void
    {
        $conversation->state = CodConversation::STATE_CANCELED;
        $conversation->save();
    }

    /**
     * Hand the conversation to a human: the AI stops replying. Used
     * by the inbox handoff button and by AI escalation.
     */
    public function escalate(CodConversation $conversation, ?string $reason = null): void
    {
        $collected = $conversation->collected ?? [];

        if ($reason !== null && $reason !== '') {
            $collected['escalation_reason'] = mb_substr($reason, 0, 500);
        }

        $conversation->collected = $collected;
        $conversation->ai_enabled = false;
        $conversation->escalated_at = now();
        $conversation->state = CodConversation::STATE_ESCALATED;
        $conversation->save();
    }

    /**
     * Count an unclear reply on the conversation. After two unclear
     * replies the AI escalates to a human (see the agent).
     */
    public function countUnclear(CodConversation $conversation): int
    {
        $collected = $conversation->collected ?? [];
        $collected['unclear_attempts'] = ((int) ($collected['unclear_attempts'] ?? 0)) + 1;
        $conversation->collected = $collected;
        $conversation->save();

        return (int) $collected['unclear_attempts'];
    }

    /**
     * Reset the unclear counter (the customer finally wrote something
     * understandable).
     */
    public function resetUnclear(CodConversation $conversation): void
    {
        $collected = $conversation->collected ?? [];

        if (! isset($collected['unclear_attempts'])) {
            return;
        }

        unset($collected['unclear_attempts']);
        $conversation->collected = $collected;
        $conversation->save();
    }

    /**
     * Touch the last-message timestamp (called on every inbound and
     * outbound message of the conversation).
     */
    public function touch(CodConversation $conversation): void
    {
        $conversation->last_message_at = now();
        $conversation->save();
    }

    /**
     * External id for a WhatsApp-created order: WA- + timestamp +
     * random digits. Unique by construction (the column is unique),
     * and the WA- prefix marks orders that came from WhatsApp instead
     * of the store checkout.
     */
    protected function newExternalId(): string
    {
        // M29: 900 possibilities per second was not enough — two
        // simultaneous inquiries could collide on the unique column.
        // A random 10-character suffix makes a collision negligible.
        return 'WA-'.now()->format('YmdHis').'-'.Str::random(10);
    }
}
