<?php

namespace App\Cod\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official Meta WhatsApp Cloud API provider (production provider).
 *
 * Verified against the Meta WhatsApp Business Platform docs before
 * writing (2026-11-21, via web search of developers.facebook.com
 * references and the whatsapp-cloud-api skill):
 * - POST https://graph.facebook.com/v21.0/{phone_number_id}/messages
 * - Header: Authorization: Bearer {access_token}
 * - Body always includes "messaging_product": "whatsapp".
 * - Template message: {"type": "template", "template": {"name": ...,
 *   "language": {"code": "ar"}, "components": [{"type": "body",
 *   "parameters": [{"type": "text", "text": ...}, ...]}]}}.
 * - Interactive reply buttons: {"type": "interactive", "interactive":
 *   {"type": "button", "body": {"text": ...}, "action": {"buttons":
 *   [{"type": "reply", "reply": {"id": ..., "title": ...}}]}}} (max 3
 *   buttons, title max 20 chars).
 * - Success response: {"messages": [{"id": "wamid...."}]} — that id is
 *   stored on the message log for audit.
 * - Error 131047 = outside the 24-hour customer-service window: only a
 *   template may be sent. The confirmation sender handles this by
 *   falling back to the configured template when an interactive send
 *   fails with 131047.
 * - Error 132000 = template parameter count mismatch.
 *
 * Credentials come only from the admin
 * (general.cod_system.whatsapp.meta_*): access token (password field),
 * Phone Number ID, Business ID, template name + language, app secret
 * (webhook signature). Nothing is hard-coded and the token is never
 * logged.
 *
 * Open point: the approved Meta template must exist before production
 * use, with body parameters in the order this code sends them
 * (order_id, name, total, products) and — to receive button taps — three
 * quick-reply buttons. The button ids returned by Meta for template
 * quick replies are the ids defined in the template itself, so the
 * webhook also interprets the button title text as a fallback.
 */
class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    public const CONFIG_KEY = 'general.cod_system.whatsapp';

    public const GRAPH_VERSION = 'v21.0';

    public const BUTTON_CONFIRM = 'cod_confirm';

    public const BUTTON_POSTPONE = 'cod_postpone';

    public const BUTTON_CANCEL = 'cod_cancel';

    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getToken()) && ! empty($this->getPhoneNumberId());
    }

    public function getToken(): ?string
    {
        $token = core()->getConfigData(self::CONFIG_KEY.'.meta_access_token');

        return $token ? (string) $token : null;
    }

    public function getPhoneNumberId(): ?string
    {
        $id = core()->getConfigData(self::CONFIG_KEY.'.meta_phone_number_id');

        return $id ? (string) $id : null;
    }

    public function getTemplateName(): ?string
    {
        $name = core()->getConfigData(self::CONFIG_KEY.'.meta_template_name');

        return $name ? (string) $name : null;
    }

    public function getTemplateLanguage(): string
    {
        $language = core()->getConfigData(self::CONFIG_KEY.'.meta_template_language');

        return $language ? (string) $language : 'ar';
    }

    /**
     * Plain text message. Only works inside the 24-hour window; outside
     * it Meta rejects with 131047 — callers that must reach the customer
     * should use sendTemplate() instead.
     */
    public function sendText(string $phone, string $text): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($phone),
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => false],
        ]);
    }

    public function sendInteractive(string $phone, string $text, array $buttons): array
    {
        $buttons = array_slice($buttons, 0, 3);

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($phone),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $text],
                'action' => [
                    'buttons' => array_map(fn (array $button) => [
                        'type' => 'reply',
                        'reply' => [
                            'id' => (string) $button['id'],
                            // Meta caps button titles at 20 characters.
                            'title' => mb_substr((string) $button['title'], 0, 20),
                        ],
                    ], $buttons),
                ],
            ],
        ]);
    }

    public function sendTemplate(string $phone, string $templateName, string $language, array $parameters): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(fn ($value) => [
                            'type' => 'text',
                            'text' => (string) $value,
                        ], array_values($parameters)),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Meta wants the recipient in E.164 without the leading '+'
     * (e.g. 2126XXXXXXXX). Stored numbers may be 06..., +2126... or
     * 2126... — normalize to digits, then swap a leading 0 for 212.
     */
    protected function formatRecipient(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '212'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '212'.$digits;
        }

        return $digits;
    }

    /**
     * One POST to the Graph API. Returns the wamid on success; on
     * failure the Meta error code/message (never the token).
     */
    protected function post(array $payload): array
    {
        $token = $this->getToken();
        $phoneNumberId = $this->getPhoneNumberId();

        if (empty($token) || empty($phoneNumberId)) {
            return ['success' => false, 'message' => 'Meta WhatsApp is not configured (access token or Phone Number ID missing).', 'message_id' => null];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
                ->post('https://graph.facebook.com/'.self::GRAPH_VERSION.'/'.urlencode($phoneNumberId).'/messages', $payload);
        } catch (ConnectionException $e) {
            Log::warning('COD WhatsApp (Meta): connection failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Connection to graph.facebook.com failed: '.$e->getMessage(), 'message_id' => null];
        }

        $body = $this->safeBody($response);

        if ($response->successful()) {
            $messageId = $body['messages'][0]['id'] ?? null;

            return ['success' => true, 'message' => 'Message sent via Meta WhatsApp Cloud API.', 'message_id' => is_string($messageId) ? $messageId : null];
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = $error['code'] ?? $response->status();
        $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP '.$response->status();

        Log::warning('COD WhatsApp (Meta): send failed', ['code' => $code, 'error' => $errorMessage]);

        return ['success' => false, 'message' => "Meta WhatsApp error {$code}: {$errorMessage}", 'message_id' => null, 'error_code' => $code];
    }

    protected function safeBody($response): array
    {
        try {
            $json = $response->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }
}
