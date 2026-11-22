<?php

namespace App\Cod\WhatsApp;

use App\Cod\Ai\ConversationAgent;
use App\Cod\Ai\ConversationAgentInterface;
use App\Cod\Ai\ReplyIntentClassifierInterface;
use App\Cod\Automation\AutomationSettings;
use App\Cod\Conversations\ConversationServiceInterface;
use App\Cod\Conversations\ProductMatcher;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Support\PhoneNumber;
use App\Models\CodCallLog;
use App\Models\CodConversation;
use App\Models\CodOrder;
use App\Models\CodWhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp for the COD System (phase 4).
 *
 * Reply understanding: interpretReply() asks the LLM classifier
 * (app/Cod/Ai/, OpenAI-compatible chat completions, admin switch in
 * COD System → AI) FIRST when it is enabled and configured. When the
 * AI is disabled, unconfigured or fails — or when it answers
 * "question" / "unclear" — the behaviour is exactly the old one:
 * keyword matching below, and question / unclear change no order
 * status, they are only recorded. A failed AI can therefore never
 * break confirmations.
 *
 * Sending goes through the provider selected in the admin
 * (general.cod_system.whatsapp.provider): Meta Cloud API for
 * production, Evolution API for testing only, and a log provider when
 * nothing is configured — so no real message is ever sent while the
 * tokens are missing.
 *
 * Confirmation hook-up point (chosen deliberately, documented here):
 * - AssignmentService::assign() and manualAssign() call
 *   sendConfirmation() right after an order is assigned to a
 *   confirmer, and OrderService::storeCodOrder() calls it for a newly
 *   imported pending order that could NOT be assigned (automatic
 *   assignment off, or no confirmer available). Either way the
 *   customer gets exactly one confirmation message: sendConfirmation()
 *   refuses to send twice (confirmation_sent_at), and a failure leaves
 *   the field empty so the next trigger retries.
 * - The reminder command (cod:remind-unconfirmed) calls
 *   sendReminder() once per order (reminder_sent_at).
 *
 * Message templates live in the admin
 * (general.cod_system.whatsapp.confirmation_message /
 * reminder_message) with placeholders {order_id} {name} {total}
 * {products} {store}. {store} is the application name from config
 * (APP_NAME, "Jemla En Ligne" locally) — NOT the Bagisto channel name,
 * which is still "Default" on fresh installs.
 *
 * Meta vs Evolution difference (documented in both providers too):
 * Meta sends the confirmation as an approved template message when a
 * template name is configured (business-initiated messages must be
 * templates outside the 24-hour window), otherwise as an interactive
 * message with three reply buttons. Evolution has no templates and its
 * WhatsApp Web client does not render buttons reliably, so it sends
 * the same text plus numbered options (1=confirm, 2=postpone,
 * 3=cancel) and the customer answers with a digit or a word.
 *
 * WhatsApp Automation (COD System → WhatsApp Automation, off by
 * default): when it is on, processInbound() answers first with the
 * conversation agent (app/Cod/Ai/ConversationAgent: full history +
 * COD facts + the admin prompt, natural Darija, persuasive) and only
 * falls back to interpretReply() when the agent is unavailable. A
 * message with no pending order starts the product-inquiry flow
 * (app/Cod/Conversations: product matching, four questions one by
 * one, summary, order creation). A confirmed order gets the
 * thank-you message and, when the setting says so, its parcel is
 * sent to the delivery provider. The reminder command moves orders
 * with no reply to `whatsapp_unconfirmed` after the deadline — never
 * canceled automatically. A conversation handed to a human gets no
 * automatic reply at all. Automation off keeps the phase 4 behaviour
 * exactly as it was.
 */
class WhatsAppService implements WhatsAppServiceInterface
{
    public const CONFIG_KEY = 'general.cod_system.whatsapp';

    public const PROVIDER_META = 'meta';

    public const PROVIDER_EVOLUTION = 'evolution';

    public const PROVIDER_NONE = 'none';

    /**
     * Delivery-status notification events (phase 6). Each maps to one
     * admin-editable message template and one *_notification_sent_at
     * column on CodOrder, so each event is notified at most once per
     * order.
     */
    public const STATUS_NOTIFICATION_EVENTS = [
        'shipped',
        'out_for_delivery',
        'delivered',
        'returned',
    ];

    /**
     * Notification event => CodOrder column marking it sent.
     */
    public const STATUS_NOTIFICATION_COLUMNS = [
        'shipped' => 'shipped_notification_sent_at',
        'out_for_delivery' => 'out_for_delivery_notification_sent_at',
        'delivered' => 'delivered_notification_sent_at',
        'returned' => 'returned_notification_sent_at',
    ];

    /**
     * Reply-button ids used by the Meta interactive message. Template
     * quick-reply buttons use the ids defined in the template itself,
     * so the webhook also interprets the button title text.
     */
    public const BUTTONS = [
        ['id' => MetaWhatsAppProvider::BUTTON_CONFIRM, 'title' => 'أكد الطلب'],
        ['id' => MetaWhatsAppProvider::BUTTON_POSTPONE, 'title' => 'أجل الطلب'],
        ['id' => MetaWhatsAppProvider::BUTTON_CANCEL, 'title' => 'ألغي الطلب'],
    ];

    protected ?ReplyIntentClassifierInterface $classifier;

    protected AutomationSettings $automation;

    protected ConversationAgentInterface $agent;

    protected ?ConversationServiceInterface $conversations = null;

    protected ?ProductMatcher $productMatcher = null;

    protected ?DeliveryServiceInterface $delivery = null;

    /**
     * The classifier is optional so the service keeps working exactly
     * as before when no AI is bound (e.g. plain `new WhatsAppService()`
     * in a test): interpretReply() then only does keyword matching.
     *
     * The conversation service, the product matcher and the delivery
     * service are resolved lazily (see the getters below), NOT
     * injected in the constructor: the conversation service needs the
     * order service, which needs this WhatsApp service — injecting
     * it here would be a circular dependency and the container could
     * not build the singleton.
     */
    public function __construct(
        ?ReplyIntentClassifierInterface $classifier = null,
        ?AutomationSettings $automation = null,
        ?ConversationAgentInterface $agent = null,
    ) {
        $this->classifier = $classifier;
        $this->automation = $automation ?? new AutomationSettings;
        $this->agent = $agent ?? new ConversationAgent($this->classifier, $this->automation);
    }

    /**
     * The conversation service, resolved on first use (see the
     * constructor note about the circular dependency).
     */
    protected function conversationService(): ConversationServiceInterface
    {
        return $this->conversations ??= app(ConversationServiceInterface::class);
    }

    /**
     * The product matcher, resolved on first use.
     */
    protected function productMatcher(): ProductMatcher
    {
        return $this->productMatcher ??= new ProductMatcher;
    }

    /**
     * The delivery service, resolved on first use (null when it
     * cannot be resolved: sending the parcel is then skipped).
     */
    protected function deliveryService(): ?DeliveryServiceInterface
    {
        if ($this->delivery === null) {
            try {
                $this->delivery = app(DeliveryServiceInterface::class);
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->delivery;
    }

    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    public function getPhoneNumber(): ?string
    {
        $number = core()->getConfigData('general.general.whatsapp.phone_number');

        return $number ? (string) $number : null;
    }

    public function providerName(): string
    {
        $provider = core()->getConfigData(self::CONFIG_KEY.'.provider');

        return in_array($provider, [self::PROVIDER_META, self::PROVIDER_EVOLUTION], true)
            ? (string) $provider
            : self::PROVIDER_NONE;
    }

    public function sendMessage(string $phone, string $message): array
    {
        $result = $this->resolveProvider()->sendText($phone, $message);

        $this->logMessage(null, CodWhatsAppMessage::DIRECTION_OUT, $phone, $message, $result, null, CodWhatsAppMessage::SENDER_HUMAN);

        return $result;
    }

    /**
     * Send one message in a conversation (AI agent reply or manual
     * inbox reply) through the selected provider, and record it with
     * its sender so the inbox can tell AI / human / system messages
     * apart. The conversation's linked order is recorded when there
     * is one. Never throws: a provider failure is returned, not
     * raised.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendConversationMessage(CodConversation $conversation, string $text, string $sender): array
    {
        $result = $this->resolveProvider()->sendText($conversation->phone, $text);

        $this->logMessage(
            $conversation->codOrder,
            CodWhatsAppMessage::DIRECTION_OUT,
            $conversation->phone,
            $text,
            $result,
            null,
            in_array($sender, CodWhatsAppMessage::SENDERS, true) ? $sender : CodWhatsAppMessage::SENDER_SYSTEM,
        );

        return $result;
    }

    public function sendConfirmation(CodOrder $order): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'COD WhatsApp is disabled in the admin.', 'message_id' => null];
        }

        if ($order->status !== CodOrder::STATUS_PENDING_CONFIRMATION) {
            return ['success' => false, 'message' => 'Order is no longer pending confirmation.', 'message_id' => null];
        }

        // Idempotency: exactly one confirmation message per order.
        if ($order->confirmation_sent_at) {
            return ['success' => true, 'message' => 'Confirmation message was already sent for this order.', 'message_id' => null];
        }

        $phone = $order->customer_phone;

        if (empty($phone)) {
            return ['success' => false, 'message' => 'Order has no customer phone number.', 'message_id' => null];
        }

        $provider = $this->resolveProvider();
        $text = $this->renderTemplate($this->confirmationTemplate(), $order);

        if ($provider instanceof MetaWhatsAppProvider) {
            $templateName = $provider->getTemplateName();

            if ($templateName) {
                // Business-initiated: an approved template is required
                // outside the 24-hour customer-service window. The
                // template must have body parameters in this order:
                // order_id, name, total, products.
                $result = $provider->sendTemplate($phone, $templateName, $provider->getTemplateLanguage(), [
                    $order->external_id,
                    $order->customer_name ?? '',
                    $this->formatTotal($order),
                    $this->productNames($order),
                ]);
            } else {
                $result = $provider->sendInteractive($phone, $text, self::BUTTONS);

                // 131047 = outside the 24h window: only a template may
                // be sent. Without a configured template there is no
                // fallback — say so plainly instead of pretending.
                if (! $result['success'] && ($result['error_code'] ?? null) === 131047) {
                    $result['message'] .= ' Configure an approved Meta template (COD System → WhatsApp → Meta template name) to reach customers outside the 24-hour window.';
                }
            }
        } else {
            $result = $provider->sendInteractive($phone, $text, self::BUTTONS);
        }

        $this->logMessage($order, CodWhatsAppMessage::DIRECTION_OUT, $phone, $text, $result, $provider->name());

        if ($result['success']) {
            $order->confirmation_sent_at = now();
            $order->whatsapp_provider = $provider->name();
            $order->save();
        }

        return $result;
    }

    public function sendReminder(CodOrder $order): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'COD WhatsApp is disabled in the admin.', 'message_id' => null];
        }

        if ($order->status !== CodOrder::STATUS_PENDING_CONFIRMATION) {
            return ['success' => false, 'message' => 'Order is no longer pending confirmation.', 'message_id' => null];
        }

        $phone = $order->customer_phone;

        if (empty($phone)) {
            return ['success' => false, 'message' => 'Order has no customer phone number.', 'message_id' => null];
        }

        $provider = $this->resolveProvider();
        $text = $this->renderTemplate($this->reminderTemplate(), $order);

        // A reminder is business-initiated too: on Meta prefer the
        // approved template (same template as the confirmation — a
        // dedicated reminder template is an open improvement), plain
        // text only works inside the 24-hour window.
        if ($provider instanceof MetaWhatsAppProvider && $provider->getTemplateName()) {
            $result = $provider->sendTemplate($phone, $provider->getTemplateName(), $provider->getTemplateLanguage(), [
                $order->external_id,
                $order->customer_name ?? '',
                $this->formatTotal($order),
                $this->productNames($order),
            ]);
        } else {
            $result = $provider->sendInteractive($phone, $text, self::BUTTONS);
        }

        $this->logMessage($order, CodWhatsAppMessage::DIRECTION_OUT, $phone, $text, $result, $provider->name());

        if ($result['success']) {
            $order->reminder_sent_at = now();

            // Guarded: on an install where the automation migration
            // has not run yet, the column does not exist and setting
            // it would break the save (and the scheduled command).
            if (Schema::hasColumn('cod_orders', 'reminder_count')) {
                $order->reminder_count = (int) $order->reminder_count + 1;
            }

            $order->whatsapp_provider = $provider->name();
            $order->save();
        }

        return $result;
    }

    /**
     * Send one delivery-status notification to the customer (phase 6).
     *
     * Idempotency: the matching *_notification_sent_at column is
     * checked first and set only on a successful send — a second call
     * for the same event returns success with "already sent", and a
     * failed send leaves the column empty so the next sync retries.
     *
     * Meta: when an approved template name is configured for this
     * event (COD System → WhatsApp → Meta template name for the
     * event), the notification is sent as a template with the body
     * parameters in this order: order_id, name, total, products,
     * tracking_code. The template must be approved by Meta with
     * exactly those five {{1}}..{{5}} parameters, or the send fails
     * with error 132000 and is retried on the next sync. When no
     * template name is set for the event, the rendered text is sent
     * as a plain message (works only inside the 24-hour
     * customer-service window; outside it Meta rejects with 131047).
     * Evolution has no templates: the rendered text is always sent
     * as plain text.
     *
     * When WhatsApp is disabled or no provider is configured, nothing
     * is sent and nothing is logged to cod_whatsapp_messages — the
     * skip is only written to the Laravel log, and the sync that
     * triggered the notification carries on.
     */
    public function sendStatusNotification(CodOrder $order, string $event, ?string $trackingCode = null): array
    {
        if (! in_array($event, self::STATUS_NOTIFICATION_EVENTS, true)) {
            return ['success' => false, 'message' => 'Unknown delivery notification event "'.$event.'".', 'message_id' => null];
        }

        $column = self::STATUS_NOTIFICATION_COLUMNS[$event];

        // Idempotency: exactly one notification per event per order.
        if ($order->{$column}) {
            return ['success' => true, 'message' => 'The '.$event.' notification was already sent for this order.', 'message_id' => null];
        }

        if (! $this->isEnabled()) {
            Log::info('COD WhatsApp: status notification skipped, WhatsApp is disabled', [
                'cod_order_id' => $order->id,
                'event' => $event,
            ]);

            return ['success' => false, 'message' => 'COD WhatsApp is disabled in the admin.', 'message_id' => null];
        }

        $phone = $order->customer_phone;

        if (empty($phone)) {
            return ['success' => false, 'message' => 'Order has no customer phone number.', 'message_id' => null];
        }

        $provider = $this->resolveProvider();

        // No real provider configured (provider 'none', or the
        // selected provider has no credentials): skip silently, do
        // not mark the notification sent, do not fail the sync.
        if ($provider instanceof LogWhatsAppProvider) {
            Log::info('COD WhatsApp: status notification skipped, no WhatsApp provider is configured', [
                'cod_order_id' => $order->id,
                'event' => $event,
            ]);

            return ['success' => false, 'message' => 'No WhatsApp provider is configured.', 'message_id' => null];
        }

        $text = $this->renderTemplate($this->statusNotificationTemplate($event), $order, $trackingCode);

        if ($provider instanceof MetaWhatsAppProvider) {
            $templateName = $this->statusTemplateName($event);

            if ($templateName) {
                $result = $provider->sendTemplate($phone, $templateName, $provider->getTemplateLanguage(), [
                    $order->external_id,
                    $order->customer_name ?? '',
                    $this->formatTotal($order),
                    $this->productNames($order),
                    $trackingCode ?? '',
                ]);
            } else {
                $result = $provider->sendText($phone, $text);

                if (! $result['success'] && ($result['error_code'] ?? null) === 131047) {
                    $result['message'] .= ' Configure an approved Meta template for this event (COD System → WhatsApp → Meta template name) to reach customers outside the 24-hour window.';
                }
            }
        } else {
            $result = $provider->sendText($phone, $text);
        }

        $this->logMessage($order, CodWhatsAppMessage::DIRECTION_OUT, $phone, $text, $result, $provider->name());

        if ($result['success']) {
            $order->{$column} = now();
            $order->whatsapp_provider = $provider->name();
            $order->save();
        }

        return $result;
    }

    public function normalizePhone(string $phone): string
    {
        return PhoneNumber::normalize($phone);
    }

    public function interpretReply(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim(mb_strtolower($text));

        if ($clean === '') {
            return null;
        }

        // LLM first: when the AI is enabled and configured and answers,
        // its verdict wins. confirmed / postponed / canceled change the
        // order; question / unclear change nothing (null, same as an
        // unrecognized reply today). When the AI is disabled,
        // unconfigured or fails it returns null and we fall through to
        // the keyword matcher below — the old behaviour, unchanged.
        if ($this->classifier !== null) {
            $intent = $this->classifier->classify($text);

            if ($intent !== null) {
                return match ($intent) {
                    ReplyIntentClassifierInterface::INTENT_CONFIRMED => 'confirmed',
                    ReplyIntentClassifierInterface::INTENT_POSTPONED => 'postponed',
                    ReplyIntentClassifierInterface::INTENT_CANCELED => 'canceled',
                    default => null,
                };
            }
        }

        // Evolution numbered text: a leading digit decides (1/2/3).
        if (preg_match('/^([123])[\s\.\-\)]*/u', $clean, $matches)) {
            return match ($matches[1]) {
                '1' => 'confirmed',
                '2' => 'postponed',
                '3' => 'canceled',
            };
        }

        $postponed = ['أجل', 'اجل', 'لاحق', 'بعدين', 'غدا', 'غداً', 'غد', 'delay', 'postpone', 'plus tard', 'demain', 'rappel', 'call me later', 'later'];
        $canceled = ['ألغ', 'الغ', 'إلغاء', 'الغاء', 'cancel', 'annul', 'refus', 'رفض', 'لا أريد', 'لا اريد', 'ما بغيت', 'مابغيت', 'بغيتوش', 'بغيتش', 'non merci'];
        $confirmed = ['أكد', 'اكد', 'أؤكد', 'اؤكد', 'نعم', 'موافق', 'أكيد', 'اكيد', 'oui', 'yes', 'confirm', 'ok', 'okay'];

        foreach ($postponed as $word) {
            if (str_contains($clean, $word)) {
                return 'postponed';
            }
        }

        foreach ($canceled as $word) {
            if (str_contains($clean, $word)) {
                return 'canceled';
            }
        }

        foreach ($confirmed as $word) {
            if (str_contains($clean, $word)) {
                return 'confirmed';
            }
        }

        // A bare "لا" answers "واش نأكدو؟" with no: cancel.
        if ($clean === 'لا' || $clean === 'no' || $clean === 'non') {
            return 'canceled';
        }

        return null;
    }

    public function processInbound(string $provider, string $phone, ?string $text, ?string $buttonId = null, ?string $externalMessageId = null): array
    {
        $normalized = $this->normalizePhone($phone);

        // De-duplication: Meta and Evolution may redeliver a webhook.
        if ($externalMessageId) {
            $duplicate = CodWhatsAppMessage::query()
                ->where('provider', $provider)
                ->where('direction', CodWhatsAppMessage::DIRECTION_IN)
                ->where('message_id', $externalMessageId)
                ->exists();

            if ($duplicate) {
                return ['matched' => false, 'message' => 'Duplicate webhook delivery ignored.'];
            }
        }

        $order = $this->findPendingOrderByPhone($normalized);

        $this->logMessage($order, CodWhatsAppMessage::DIRECTION_IN, $phone, (string) $text, [
            'success' => true,
            'message' => 'received',
            'message_id' => $externalMessageId,
            'status' => CodWhatsAppMessage::STATUS_RECEIVED,
        ], $provider);

        // Automation on: the conversation agent answers first (full
        // history + COD facts + the admin prompt), the product
        // inquiry flow starts when there is no order, and the old
        // behaviour below is only the fallback when the agent is
        // unavailable. Automation off: the old behaviour, unchanged.
        // Guarded: without the cod_conversations table (migration
        // not run yet) the automation cannot track anything, so the
        // old behaviour runs instead of breaking the webhook.
        if ($this->automation->isEnabled() && Schema::hasTable('cod_conversations')) {
            return $this->processInboundAutomated($provider, $normalized, (string) $text, $buttonId, $order);
        }

        if ($this->automation->isEnabled()) {
            Log::warning('COD WhatsApp automation: cod_conversations table is missing — run php artisan migrate, falling back to the non-automated flow.');
        }

        if (! $order) {
            Log::info('COD WhatsApp: inbound message with no matching pending order', [
                'provider' => $provider,
                'phone' => $normalized,
            ]);

            return ['matched' => false, 'message' => 'No pending-confirmation order for this phone number.'];
        }

        $outcome = match ($buttonId) {
            MetaWhatsAppProvider::BUTTON_CONFIRM => 'confirmed',
            MetaWhatsAppProvider::BUTTON_POSTPONE => 'postponed',
            MetaWhatsAppProvider::BUTTON_CANCEL => 'canceled',
            default => $this->interpretReply($text),
        };

        // Template quick-reply buttons report the ids defined in the
        // template, not ours — fall back to the button title text.
        if ($outcome === null && $buttonId !== null) {
            $outcome = $this->interpretReply($buttonId);
        }

        $order->last_reply_at = now();
        $order->save();

        if ($outcome === null) {
            return ['matched' => true, 'message' => 'Reply recorded, but it is not a confirmation answer (confirm / postpone / cancel).', 'outcome' => null];
        }

        $this->applyOutcome($order, $outcome, (string) $text);

        return ['matched' => true, 'message' => "Order {$order->external_id} updated: {$outcome}.", 'outcome' => $outcome];
    }

    /**
     * The automated inbound flow (automation on).
     *
     * - A conversation is tracked for the phone (linked to the pending
     *   order when there is one). A conversation handed to a human
     *   gets no automatic reply at all.
     * - With a pending order: the agent answers first (full history +
     *   COD facts). Its intent drives the order: confirm / cancel /
     *   postpone apply the outcome (and send the parcel when the
     *   setting says so), a question gets the agent's answer, an
     *   escalation hands the conversation to a human. When the agent
     *   is unavailable, the old interpretReply path below takes over.
     * - Without a pending order: the message is a product inquiry.
     *   An ongoing collection is continued (agent first, deterministic
     *   questions as fallback); a new message is matched to a product
     *   (single match starts the collection, several / none ask for
     *   clarification).
     */
    protected function processInboundAutomated(string $provider, string $normalized, string $text, ?string $buttonId, ?CodOrder $order): array
    {
        $conversation = $this->conversationService()->findOrCreateByPhone($normalized);

        if ($order && ! $conversation->cod_order_id) {
            $conversation->cod_order_id = $order->id;
            $conversation->save();
        }

        $this->conversationService()->touch($conversation);

        if ($conversation->isEscalated()) {
            if ($order) {
                $order->last_reply_at = now();
                $order->save();
            }

            Log::info('COD WhatsApp automation: inbound message on a conversation handed to a human, no auto reply', [
                'provider' => $provider,
                'phone' => $normalized,
            ]);

            return ['matched' => true, 'message' => 'Conversation handed to a human, no automatic reply.'];
        }

        // Button taps are deterministic: apply the outcome right away
        // (and the thank-you / cancel message), the agent is not
        // needed to understand a button.
        $buttonOutcome = match ($buttonId) {
            MetaWhatsAppProvider::BUTTON_CONFIRM => 'confirmed',
            MetaWhatsAppProvider::BUTTON_POSTPONE => 'postponed',
            MetaWhatsAppProvider::BUTTON_CANCEL => 'canceled',
            default => null,
        };

        if ($buttonOutcome !== null && $order) {
            $this->applyOutcome($order, $buttonOutcome, $text, $conversation);

            return ['matched' => true, 'message' => "Order {$order->external_id} updated: {$buttonOutcome}.", 'outcome' => $buttonOutcome];
        }

        if ($order) {
            return $this->processOrderReply($conversation, $order, $text);
        }

        return $this->processProductInquiry($conversation, $text);
    }

    /**
     * One inbound reply about a pending order, automation on.
     */
    protected function processOrderReply(CodConversation $conversation, CodOrder $order, string $text): array
    {
        $order->last_reply_at = now();
        $order->save();

        $agentResult = $this->agent->generate($conversation, $text, [
            'order' => $order,
            'product' => $conversation->product(),
            'collected' => $conversation->answers(),
        ]);

        if ($agentResult !== null) {
            return $this->applyAgentResult($conversation, $order, $agentResult);
        }

        // Agent unavailable: the old behaviour — classifier first,
        // keywords second. A confirm / cancel also sends the
        // thank-you / cancel message.
        $outcome = $this->interpretReply($text);

        if ($outcome === null) {
            return ['matched' => true, 'message' => 'Reply recorded, but it is not a confirmation answer (confirm / postpone / cancel).', 'outcome' => null];
        }

        $this->applyOutcome($order, $outcome, $text, $conversation);

        return ['matched' => true, 'message' => "Order {$order->external_id} updated: {$outcome}.", 'outcome' => $outcome];
    }

    /**
     * Apply what the agent understood from a reply about a pending
     * order: send its reply, then act on its intent.
     */
    protected function applyAgentResult(CodConversation $conversation, CodOrder $order, array $agentResult): array
    {
        $intent = $agentResult['intent'];

        if ($agentResult['escalate']) {
            $this->conversationService()->escalate($conversation, $agentResult['summary'] ?? 'Escalated by the AI agent.');
            $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Conversation escalated to a human.', 'outcome' => 'escalated'];
        }

        if ($intent === ConversationAgentInterface::INTENT_UNCLEAR) {
            $unclear = $this->conversationService()->countUnclear($conversation);

            if ($unclear >= 2) {
                $this->conversationService()->escalate($conversation, 'Message not understood after two tries.');
                $this->sendConversationMessage($conversation, AutomationSettings::DEFAULT_HANDOFF_MESSAGE, CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Conversation escalated to a human (not understood after two tries).', 'outcome' => 'escalated'];
            }

            $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Reply recorded, unclear (attempt '.$unclear.' of 2).', 'outcome' => null];
        }

        $this->conversationService()->resetUnclear($conversation);
        $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

        $outcome = match ($intent) {
            ConversationAgentInterface::INTENT_CONFIRM => 'confirmed',
            ConversationAgentInterface::INTENT_CANCEL => 'canceled',
            ConversationAgentInterface::INTENT_POSTPONE => 'postponed',
            default => null,
        };

        if ($outcome === null) {
            return ['matched' => true, 'message' => 'Reply answered by the AI agent.', 'outcome' => null];
        }

        $this->applyOutcome($order, $outcome, $agentResult['reply'], $conversation, false);

        return ['matched' => true, 'message' => "Order {$order->external_id} updated: {$outcome}.", 'outcome' => $outcome];
    }

    /**
     * One inbound message with no pending order: a product inquiry
     * (or a follow-up in an ongoing inquiry).
     */
    protected function processProductInquiry(CodConversation $conversation, string $text): array
    {
        // An inquiry already in progress: continue the collection.
        if ($conversation->state === CodConversation::STATE_AWAITING_CONFIRMATION) {
            return $this->processAwaitingConfirmation($conversation, $text);
        }

        if ($conversation->state === CodConversation::STATE_COLLECTING_INFO && $conversation->product() !== null) {
            return $this->processCollectingInfo($conversation, $text);
        }

        // A new inquiry: match the message to a product.
        $match = $this->productMatcher()->match($text);

        if ($match['status'] === ProductMatcher::MATCH_SINGLE) {
            $product = $match['products'][0];
            $firstQuestion = $this->conversationService()->startProductInquiry($conversation, $product);

            $agentResult = $this->agent->generate($conversation, $text, [
                'order' => null,
                'product' => $product,
                'next_field' => $conversation->nextField(),
                'collected' => $conversation->answers(),
            ]);

            $reply = $agentResult['reply'] ?? $firstQuestion;

            $this->sendConversationMessage($conversation, $reply, CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Product inquiry started: '.$product['sku'].'.'];
        }

        // Several products or none: ask for clarification (agent
        // first, canned message when the agent is unavailable).
        $agentResult = $this->agent->generate($conversation, $text, [
            'order' => null,
            'product' => null,
            'product_candidates' => $match['products'],
        ]);

        $reply = $agentResult['reply'] ?? 'واش ممكن توضح ليا المنتج اللي بغيتي؟ عاود كتب ليا الاسم والثمن ديالو.';

        $this->sendConversationMessage($conversation, $reply, CodWhatsAppMessage::SENDER_AI);

        return ['matched' => true, 'message' => 'Product inquiry needs clarification ('.$match['status'].').'];
    }

    /**
     * A follow-up while the information is being collected: the agent
     * understands the answer (which field, which value) and the
     * deterministic next question is sent, so the flow can never ask
     * for a field the customer just gave.
     */
    protected function processCollectingInfo(CodConversation $conversation, string $text): array
    {
        $agentResult = $this->agent->generate($conversation, $text, [
            'order' => null,
            'product' => $conversation->product(),
            'next_field' => $conversation->nextField(),
            'collected' => $conversation->answers(),
        ]);

        if ($agentResult !== null) {
            if ($agentResult['escalate']) {
                $this->conversationService()->escalate($conversation, $agentResult['summary'] ?? 'Escalated by the AI agent.');
                $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Conversation escalated to a human.', 'outcome' => 'escalated'];
            }

            if ($agentResult['intent'] === ConversationAgentInterface::INTENT_CANCEL) {
                $this->conversationService()->cancel($conversation);
                $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Product inquiry canceled by the customer.', 'outcome' => 'canceled'];
            }

            if ($agentResult['intent'] === ConversationAgentInterface::INTENT_COLLECT_INFO && $agentResult['field'] !== null && $agentResult['value'] !== null) {
                $this->conversationService()->resetUnclear($conversation);
                $next = $this->conversationService()->recordAnswer($conversation, $agentResult['field'], $agentResult['value']);
                $this->sendConversationMessage($conversation, $next, CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Collected '.$agentResult['field'].'.'];
            }

            if ($agentResult['intent'] === ConversationAgentInterface::INTENT_UNCLEAR) {
                $unclear = $this->conversationService()->countUnclear($conversation);

                if ($unclear >= 2) {
                    $this->conversationService()->escalate($conversation, 'Message not understood after two tries.');
                    $this->sendConversationMessage($conversation, AutomationSettings::DEFAULT_HANDOFF_MESSAGE, CodWhatsAppMessage::SENDER_AI);

                    return ['matched' => true, 'message' => 'Conversation escalated to a human (not understood after two tries).', 'outcome' => 'escalated'];
                }

                $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Reply recorded, unclear (attempt '.$unclear.' of 2).', 'outcome' => null];
            }

            // A question (or anything else understood): the agent's
            // answer, then the current question is asked again on the
            // next turn.
            $this->conversationService()->resetUnclear($conversation);
            $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Reply answered by the AI agent.'];
        }

        // Agent unavailable: a cancel is honored, a question gets the
        // current question repeated, anything else is the answer to
        // the current question.
        if ($this->interpretReply($text) === 'canceled') {
            $this->conversationService()->cancel($conversation);

            return ['matched' => true, 'message' => 'Product inquiry canceled by the customer.', 'outcome' => 'canceled'];
        }

        if ($this->looksLikeQuestion($text)) {
            $this->sendConversationMessage($conversation, $this->conversationService()->questionFor($conversation), CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Reply recorded, question repeated.'];
        }

        $field = $conversation->nextField();

        if ($field === null) {
            return ['matched' => true, 'message' => 'Reply recorded.'];
        }

        $next = $this->conversationService()->recordAnswer($conversation, $field, $text);
        $this->sendConversationMessage($conversation, $next, CodWhatsAppMessage::SENDER_AI);

        return ['matched' => true, 'message' => 'Collected '.$field.'.'];
    }

    /**
     * A follow-up once everything is collected and the summary was
     * shown: confirm creates the real order, cancel cancels the
     * inquiry.
     */
    protected function processAwaitingConfirmation(CodConversation $conversation, string $text): array
    {
        $agentResult = $this->agent->generate($conversation, $text, [
            'order' => null,
            'product' => $conversation->product(),
            'collected' => $conversation->answers(),
        ]);

        if ($agentResult !== null) {
            if ($agentResult['escalate']) {
                $this->conversationService()->escalate($conversation, $agentResult['summary'] ?? 'Escalated by the AI agent.');
                $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Conversation escalated to a human.', 'outcome' => 'escalated'];
            }

            if ($agentResult['intent'] === ConversationAgentInterface::INTENT_CONFIRM) {
                return $this->confirmProductInquiry($conversation, $agentResult['reply']);
            }

            if ($agentResult['intent'] === ConversationAgentInterface::INTENT_CANCEL) {
                $this->conversationService()->cancel($conversation);
                $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

                return ['matched' => true, 'message' => 'Product inquiry canceled by the customer.', 'outcome' => 'canceled'];
            }

            $this->sendConversationMessage($conversation, $agentResult['reply'], CodWhatsAppMessage::SENDER_AI);

            return ['matched' => true, 'message' => 'Reply answered by the AI agent.'];
        }

        $outcome = $this->interpretReply($text);

        if ($outcome === 'confirmed') {
            return $this->confirmProductInquiry($conversation, null);
        }

        if ($outcome === 'canceled') {
            $this->conversationService()->cancel($conversation);

            return ['matched' => true, 'message' => 'Product inquiry canceled by the customer.', 'outcome' => 'canceled'];
        }

        return ['matched' => true, 'message' => 'Reply recorded, waiting for confirmation.'];
    }

    /**
     * The customer confirmed the summary: create the real order (when
     * automatic creation is on) and thank them. When automatic
     * creation is off, the conversation stays awaiting confirmation
     * for a human to create the order.
     */
    protected function confirmProductInquiry(CodConversation $conversation, ?string $agentReply): array
    {
        $order = $this->conversationService()->createOrder($conversation);

        if ($order === null) {
            if ($agentReply !== null) {
                $this->sendConversationMessage($conversation, $agentReply, CodWhatsAppMessage::SENDER_AI);
            }

            return ['matched' => true, 'message' => 'Order creation is off: conversation waits for a human to create the order.'];
        }

        $text = $agentReply ?? $this->renderTemplate($this->automation->thankYouMessage(), $order);

        $this->sendConversationMessage($conversation, $text, CodWhatsAppMessage::SENDER_AI);

        return ['matched' => true, 'message' => "Order {$order->external_id} created from WhatsApp inquiry.", 'outcome' => 'confirmed'];
    }

    /**
     * Whether a free-text reply looks like a question rather than an
     * answer: a question mark or a question word. Used by the
     * no-agent fallback so a question is never recorded as the answer
     * to the current collection question.
     */
    protected function looksLikeQuestion(string $text): bool
    {
        $clean = mb_strtolower(trim($text));

        if (str_contains($clean, '?') || str_contains($clean, '؟')) {
            return true;
        }

        foreach (['شحال', 'واش', 'كيفاش', 'علاش', 'فين', 'وقتاش', 'prix', 'price', 'combien', 'comment', 'quand', 'how', 'when', 'what'] as $word) {
            if (str_contains($clean, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send the thank-you (confirmed) or cancel (canceled) message for
     * an order, from the automation settings.
     */
    protected function sendOutcomeMessage(CodConversation $conversation, CodOrder $order, string $outcome): void
    {
        $template = $outcome === 'canceled'
            ? $this->automation->cancelMessage()
            : $this->automation->thankYouMessage();

        $this->sendConversationMessage($conversation, $this->renderTemplate($template, $order), CodWhatsAppMessage::SENDER_SYSTEM);
    }

    /**
     * Reminder delay in minutes (admin, default 120). The automation
     * section owns the value now (COD System → WhatsApp Automation);
     * the older WhatsApp-section value is kept as a fallback for
     * installs that set it before the automation section existed.
     */
    public function reminderDelayMinutes(): int
    {
        return $this->automation->reminderDelayMinutes();
    }

    /**
     * The provider for the current admin settings, falling back to the
     * log provider when the selected provider is not configured — so a
     * missing token can never throw or send through a half-configured
     * API.
     */
    public function resolveProvider(): WhatsAppProviderInterface
    {
        $provider = match ($this->providerName()) {
            self::PROVIDER_META => app(MetaWhatsAppProvider::class),
            self::PROVIDER_EVOLUTION => app(EvolutionWhatsAppProvider::class),
            default => app(LogWhatsAppProvider::class),
        };

        if ($provider instanceof LogWhatsAppProvider) {
            return $provider;
        }

        if (! $provider->isConfigured()) {
            Log::warning('COD WhatsApp: selected provider is not configured, falling back to the log provider', [
                'provider' => $provider->name(),
            ]);

            return app(LogWhatsAppProvider::class);
        }

        return $provider;
    }

    /**
     * Fill the template placeholders for one order. {tracking_code}
     * is the delivery parcel code when one is known, empty otherwise.
     */
    public function renderTemplate(string $template, CodOrder $order, ?string $trackingCode = null): string
    {
        return str_replace(
            ['{order_id}', '{name}', '{total}', '{products}', '{store}', '{tracking_code}'],
            [
                $order->external_id,
                $order->customer_name ?? '',
                $this->formatTotal($order),
                $this->productNames($order),
                (string) config('app.name', 'Jemla En Ligne'),
                $trackingCode ?? '',
            ],
            $template,
        );
    }

    /**
     * Product names of one order ("Name x2, Name x1"): from the linked
     * store order items, or from the import payload when there is no
     * linked store order.
     */
    public function productNames(CodOrder $order): string
    {
        $names = [];

        if ($order->order_id) {
            $items = $order->order?->items()->get() ?? collect();

            foreach ($items as $item) {
                $names[] = trim((string) $item->name).' x'.(int) ($item->qty_ordered ?: 1);
            }
        }

        if (empty($names) && is_array($order->payload)) {
            foreach ($order->payload['items'] ?? [] as $item) {
                if (is_array($item) && ! empty($item['name'])) {
                    $qty = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
                    $names[] = trim((string) $item['name']).' x'.$qty;
                }
            }
        }

        return implode(', ', $names);
    }

    protected function confirmationTemplate(): string
    {
        $template = core()->getConfigData(self::CONFIG_KEY.'.confirmation_message');

        return $template ? (string) $template : 'السلام عليكم {name}، معك {store}. بغيت نأكد معك الطلب ديالك رقم {order_id}: {products} — المجموع {total}. واش نأكدو؟';
    }

    protected function reminderTemplate(): string
    {
        $template = core()->getConfigData(self::CONFIG_KEY.'.reminder_message');

        return $template ? (string) $template : 'السلام عليكم {name}، ما زال الطلب ديالك رقم {order_id} ({total}) بلا تأكيد. واش نأكدو؟';
    }

    /**
     * The admin-editable message text for one delivery-status
     * notification event (phase 6), with a Darija default.
     */
    protected function statusNotificationTemplate(string $event): string
    {
        $defaults = [
            'shipped' => 'السلام عليكم {name}، معك {store}. الطلب ديالك رقم {order_id}: {products} — المجموع {total}، تم الشحن ديالو وغادي يوصلك قريب إن شاء الله. كود التتبع: {tracking_code}.',
            'out_for_delivery' => 'السلام عليكم {name}، معك {store}. الطلب ديالك رقم {order_id} ({total}) خرج للتوصيل اليوم وغادي يوصلك. كود التتبع: {tracking_code}.',
            'delivered' => 'السلام عليكم {name}، معك {store}. الطلب ديالك رقم {order_id} ({total}) تم التوصيل ديالو. شكرا على الثقة ديالك.',
            'returned' => 'السلام عليكم {name}، معك {store}. الطلب ديالك رقم {order_id} ({total}) ترجع لينا. إلا بغيتي نعاودو نرسلوه ليك، تواصل معانا.',
        ];

        $template = core()->getConfigData(self::CONFIG_KEY.'.'.$event.'_message');

        return $template ? (string) $template : $defaults[$event];
    }

    /**
     * The approved Meta template name for one delivery-status event,
     * or null when none is configured (plain text is sent instead).
     * Each event has its own field: a Meta template has fixed text,
     * so the four events cannot share one template name.
     */
    protected function statusTemplateName(string $event): ?string
    {
        $name = core()->getConfigData(self::CONFIG_KEY.'.meta_'.$event.'_template_name');

        return $name ? (string) $name : null;
    }

    protected function formatTotal(CodOrder $order): string
    {
        $currency = $order->currency_code ?: 'MAD';

        return number_format((float) $order->grand_total, 2).' '.($currency === 'MAD' ? 'DH' : $currency);
    }

    /**
     * The newest pending-confirmation order whose customer phone
     * matches (compared on the last 9 digits, so 06..., +2126... and
     * 2126... all match each other).
     */
    protected function findPendingOrderByPhone(string $normalizedPhone): ?CodOrder
    {
        $suffix = substr($normalizedPhone, -9);

        if (strlen($suffix) < 9) {
            return null;
        }

        return CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNotNull('customer_phone')
            ->orderByDesc('id')
            ->get()
            ->first(fn (CodOrder $order) => substr($this->normalizePhone((string) $order->customer_phone), -9) === $suffix);
    }

    /**
     * Apply a customer reply outcome to the order and record it as a
     * call-log entry (confirmer null: the customer answered on
     * WhatsApp, no human called). Postponed keeps the order pending
     * with a callback promised for tomorrow.
     *
     * When the automation is on and a conversation is given, a
     * confirmed order also gets the thank-you message and a canceled
     * one the cancel message (unless $sendOutcomeMessage is false:
     * the agent already answered the customer). A confirmed order is
     * also sent to the delivery provider automatically when the
     * setting says so and a store order is linked — the send is
     * idempotent (an already-sent parcel is returned, not re-sent)
     * and a failure never breaks the confirmation.
     */
    protected function applyOutcome(CodOrder $order, string $outcome, string $text, ?CodConversation $conversation = null, bool $sendOutcomeMessage = true): void
    {
        $result = match ($outcome) {
            'confirmed' => CodCallLog::RESULT_CONFIRMED,
            'postponed' => CodCallLog::RESULT_POSTPONED,
            'canceled' => CodCallLog::RESULT_REFUSED,
        };

        $callbackAt = null;

        if ($outcome === 'postponed') {
            $callbackAt = now()->addDay();
            $order->callback_at = $callbackAt;
        }

        if ($outcome === 'confirmed') {
            $order->status = CodOrder::STATUS_CONFIRMED;
        } elseif ($outcome === 'canceled') {
            $order->status = CodOrder::STATUS_CANCELED;
        }

        $order->confirmation_attempts = $order->confirmation_attempts + 1;
        $order->save();

        CodCallLog::create([
            'cod_order_id' => $order->id,
            'confirmer_id' => null,
            'result' => $result,
            'note' => 'WhatsApp: '.mb_substr(trim($text), 0, 500),
            'callback_at' => $callbackAt,
            'called_at' => now(),
        ]);

        if ($conversation !== null && $sendOutcomeMessage && $this->automation->isEnabled()) {
            if ($outcome === 'confirmed' || $outcome === 'canceled') {
                $this->sendOutcomeMessage($conversation, $order, $outcome);
            }
        }

        if ($outcome === 'confirmed' && $this->automation->isEnabled() && $this->automation->autoSendToDelivery()) {
            $this->sendConfirmedOrderToDelivery($order);
        }
    }

    /**
     * Send a confirmed order to the delivery provider automatically.
     * Only for orders with a linked store order (WhatsApp-created
     * orders have none yet); a failure is logged and never breaks the
     * confirmation.
     */
    protected function sendConfirmedOrderToDelivery(CodOrder $order): void
    {
        $storeOrder = $order->order;

        if ($storeOrder === null) {
            Log::info('COD WhatsApp automation: confirmed order has no linked store order, parcel not sent automatically', [
                'cod_order_id' => $order->id,
            ]);

            return;
        }

        try {
            $this->deliveryService()?->sendOrder($storeOrder);
        } catch (\Throwable $e) {
            Log::warning('COD WhatsApp automation: failed to send confirmed order to delivery', [
                'cod_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function logMessage(?CodOrder $order, string $direction, string $phone, string $body, array $result, ?string $providerName = null, ?string $sender = null): void
    {
        try {
            CodWhatsAppMessage::create([
                'cod_order_id' => $order?->id,
                'direction' => $direction,
                'provider' => $providerName ?? $order?->whatsapp_provider ?? $this->providerNameForLog(),
                'phone' => $this->normalizePhone($phone),
                'body' => mb_substr($body, 0, 4000),
                'message_id' => $result['message_id'] ?? null,
                'status' => $result['status'] ?? ($result['success'] ? CodWhatsAppMessage::STATUS_SENT : CodWhatsAppMessage::STATUS_FAILED),
                'sender' => $sender ?? ($direction === CodWhatsAppMessage::DIRECTION_IN ? CodWhatsAppMessage::SENDER_CUSTOMER : CodWhatsAppMessage::SENDER_SYSTEM),
                'error' => $result['success'] ? null : mb_substr((string) ($result['message'] ?? ''), 0, 1000),
            ]);
        } catch (\Throwable $e) {
            // Message logging must never break sending or webhooks.
            Log::warning('COD WhatsApp: failed to log message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Provider key recorded on the message log: the real provider when
     * one is configured, otherwise "log".
     */
    protected function providerNameForLog(): string
    {
        $provider = $this->resolveProvider();

        return $provider->name();
    }
}
