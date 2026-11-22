<?php

namespace App\Cod\Ai;

use App\Cod\Automation\AutomationSettings;
use App\Models\CodConversation;
use App\Models\CodOrder;
use App\Models\CodWhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The conversational AI agent ("like sat") for COD WhatsApp.
 *
 * It uses the same OpenAI-compatible chat completions endpoint as the
 * reply classifier (admin: COD System → AI — base URL, API key,
 * model), so switching provider means changing only those three
 * settings, no code change. The API key is read from core_config on
 * every call and is never logged.
 *
 * Every call sends:
 * - the full system prompt from the automation settings (Darija,
 *   steps 1-6, strict rules: answer only with true COD facts, never
 *   invent anything, never change the price, never cancel a confirmed
 *   order, no delivery promise beyond the facts given, escalate an
 *   angry customer, an exchange / refund request, or a message that
 *   is not understood after two tries);
 * - a facts block with the true data for this conversation (order,
 *   product, collected answers, next field to ask) — the model is
 *   told to use ONLY these facts;
 * - the full conversation history (cod_whatsapp_messages for the
 *   phone, oldest first, last 30 messages) so the agent understands
 *   the conversation from the start, like a human would;
 * - the instruction to answer with one JSON object only:
 *   {"reply", "intent", "field", "value", "escalate", "summary"}.
 *
 * The reply is a natural Darija message that persuades the customer
 * — not a robotic answer and not buttons only. The intent tells the
 * caller what the customer wants (confirm / postpone / cancel /
 * question / collect_info / escalate / unclear); field + value carry
 * a collected answer (e.g. the city); escalate + summary carry the
 * handoff to a human.
 *
 * The agent NEVER throws: disabled, unconfigured, network error,
 * timeout, non-2xx, malformed or unparseable answer all return null,
 * so the caller falls back to the classifier + the canned messages
 * and the confirmation flow keeps working without the AI.
 */
class ConversationAgent implements ConversationAgentInterface
{
    /**
     * The model needs time to write a real answer, but the call
     * happens inside a webhook request: 20 seconds is the limit.
     */
    public const TIMEOUT_SECONDS = 20;

    /**
     * How many past messages are sent as context (oldest first).
     */
    public const HISTORY_LIMIT = 30;

    /**
     * Temperature for a natural, persuasive answer (the classifier
     * uses 0 because it must answer with exactly one word; the agent
     * writes free text).
     */
    public const TEMPERATURE = 0.7;

    protected ?ReplyIntentClassifierInterface $classifier;

    protected AutomationSettings $automation;

    public function __construct(?ReplyIntentClassifierInterface $classifier = null, ?AutomationSettings $automation = null)
    {
        $this->classifier = $classifier;
        $this->automation = $automation ?? new AutomationSettings;
    }

    public function isEnabled(): bool
    {
        return $this->automation->isEnabled()
            && $this->classifier !== null
            && $this->classifier->isEnabled()
            && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        return $this->classifier !== null && $this->classifier->isConfigured();
    }

    public function generate(CodConversation $conversation, string $incomingText, array $facts = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $incomingText = trim($incomingText);

        if ($incomingText === '') {
            return null;
        }

        $messages = $this->buildMessages($conversation, $incomingText, $facts);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->classifier->getApiKey(),
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($this->classifier->getBaseUrl().'/chat/completions', [
                    'model' => $this->classifier->getModel(),
                    'temperature' => self::TEMPERATURE,
                    'messages' => $messages,
                ]);
        } catch (\Throwable $e) {
            Log::warning('COD AI agent: request failed', [
                'cod_conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('COD AI agent: request failed', [
                'cod_conversation_id' => $conversation->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            Log::warning('COD AI agent: answer has no usable content', [
                'cod_conversation_id' => $conversation->id,
            ]);

            return null;
        }

        return $this->parseAnswer($content, $conversation->id);
    }

    /**
     * The chat messages: system prompt + facts + JSON instruction,
     * then the full history (in = user, out = assistant), then the
     * latest customer message — unless it is already the last history
     * item (the caller logs the inbound message before calling the
     * agent, so it usually is).
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function buildMessages(CodConversation $conversation, string $incomingText, array $facts): array
    {
        $system = $this->automation->systemPrompt()
            ."\n\nمعلومات حقيقية من النظام — استعمل غير هاد المعلومات، ما تخترع والو:\n"
            .$this->factsBlock($facts)
            ."\n\nجاوب بـ JSON فقط، بلا أي نص آخر، بهذا الشكل:\n"
            .'{"reply": "الرسالة اللي غادي ترسل للعميل بالدارجة", "intent": "confirm|postpone|cancel|question|collect_info|escalate|unclear", "field": "name|city|address|phone|null", "value": "القيمة إلا كان العميل عطا معلومة", "escalate": false, "summary": "ملخص صغير إلا كان escalate true"}'."\n"
            .'intent هو نية العميل من رسالتو الأخيرة. field و value إلا كان عطا معلومة من المعلومات الناقصة (مثلا الاسم أو المدينة). escalate true إلا كان العميل غاضبا، أو طلب تبديل أو تعويض، أو ما فهمتيش رسالتو.';

        $messages = [['role' => 'system', 'content' => $system]];

        $history = CodWhatsAppMessage::query()
            ->where('phone', $conversation->phone)
            ->orderBy('id')
            ->take(self::HISTORY_LIMIT)
            ->get(['direction', 'body']);

        $lastInboundBody = null;

        foreach ($history as $message) {
            $body = trim((string) $message->body);

            if ($body === '') {
                continue;
            }

            $messages[] = [
                'role' => $message->direction === CodWhatsAppMessage::DIRECTION_IN ? 'user' : 'assistant',
                'content' => $body,
            ];

            if ($message->direction === CodWhatsAppMessage::DIRECTION_IN) {
                $lastInboundBody = $body;
            }
        }

        // The latest message is usually already logged as the last
        // history item; only append it when it is not there yet.
        if ($lastInboundBody !== $incomingText) {
            $messages[] = ['role' => 'user', 'content' => $incomingText];
        }

        return $messages;
    }

    /**
     * The true facts for this conversation, as plain text. The agent
     * must use ONLY these: prices, products and order state come from
     * here, never from its own knowledge.
     */
    protected function factsBlock(array $facts): string
    {
        $lines = ['المتجر: '.(string) config('app.name', 'Jemla En Ligne')];

        $order = $facts['order'] ?? null;

        if ($order instanceof CodOrder) {
            $lines[] = 'الطلب رقم: '.$order->external_id;
            $lines[] = 'حالة الطلب: '.$order->status;
            $lines[] = 'الاسم: '.($order->customer_name ?? '—');
            $lines[] = 'المجموع: '.number_format((float) $order->grand_total, 2).' DH';
        } else {
            $lines[] = 'لا يوجد طلب بعد (العميل سول على منتج).';
        }

        $product = $facts['product'] ?? null;

        if (is_array($product) && ! empty($product['name'])) {
            $lines[] = 'المنتج: '.(string) $product['name'].' — '.number_format((float) ($product['price'] ?? 0), 2).' DH';
        }

        $collected = $facts['collected'] ?? [];

        if (is_array($collected) && $collected !== []) {
            $labels = ['name' => 'الاسم', 'city' => 'المدينة', 'address' => 'العنوان', 'phone' => 'التيليفون'];

            foreach ($labels as $field => $label) {
                if (! empty($collected[$field])) {
                    $lines[] = $label.' (تم جمعها): '.(string) $collected[$field];
                }
            }
        }

        $nextField = $facts['next_field'] ?? null;

        if (is_string($nextField) && $nextField !== '') {
            $labels = ['name' => 'الاسم الكامل', 'city' => 'المدينة', 'address' => 'العنوان', 'phone' => 'رقم التيليفون'];
            $lines[] = 'المعلومة الناقصة التالية: '.($labels[$nextField] ?? $nextField);
        }

        return implode("\n", $lines);
    }

    /**
     * Parse the model answer: extract the JSON object (tolerating
     * surrounding text or code fences), validate it, and normalize
     * the fields. Anything invalid returns null so the caller falls
     * back to the classifier + canned messages.
     */
    protected function parseAnswer(string $content, int $conversationId): ?array
    {
        $json = $this->extractJson($content);

        if ($json === null) {
            Log::warning('COD AI agent: answer is not valid JSON', [
                'cod_conversation_id' => $conversationId,
                'answer' => mb_substr($content, 0, 200),
            ]);

            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            Log::warning('COD AI agent: answer JSON is not an object', [
                'cod_conversation_id' => $conversationId,
            ]);

            return null;
        }

        $reply = trim((string) ($data['reply'] ?? ''));

        if ($reply === '') {
            Log::warning('COD AI agent: answer has an empty reply', [
                'cod_conversation_id' => $conversationId,
            ]);

            return null;
        }

        $intent = (string) ($data['intent'] ?? '');

        if (! in_array($intent, self::INTENTS, true)) {
            $intent = self::INTENT_UNCLEAR;
        }

        $field = $data['field'] ?? null;
        $field = is_string($field) && in_array($field, CodConversation::COLLECT_FIELDS, true) ? $field : null;

        $value = $data['value'] ?? null;
        $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

        // A collected field without a value is not a collection.
        if ($intent === self::INTENT_COLLECT_INFO && ($field === null || $value === null)) {
            $intent = self::INTENT_UNCLEAR;
        }

        $summary = $data['summary'] ?? null;
        $summary = is_string($summary) && trim($summary) !== '' ? mb_substr(trim($summary), 0, 500) : null;

        return [
            'reply' => mb_substr($reply, 0, 4000),
            'intent' => $intent,
            'field' => $field,
            'value' => $value,
            'escalate' => (bool) ($data['escalate'] ?? false) || $intent === self::INTENT_ESCALATE,
            'summary' => $summary,
        ];
    }

    /**
     * Extract the first {...} JSON object from the model answer,
     * tolerating surrounding text and ```json fences.
     */
    protected function extractJson(string $content): ?string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($content, $start, $end - $start + 1);
    }
}
