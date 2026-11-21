<?php

namespace App\Cod\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Evolution API provider — FOR TESTING ONLY.
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * WARNING — READ BEFORE ENABLING: Evolution API is an unofficial
 * WhatsApp client. It drives a normal WhatsApp account through the
 * WhatsApp Web protocol (Baileys library), NOT the official Meta
 * WhatsApp Business Platform. Using it violates WhatsApp's Terms of
 * Service and Meta can ban the phone number at any time, without
 * warning. Never use it with the store's real business number in
 * production, and never for customer traffic at scale. It exists here
 * only so the confirmation flow can be tested end-to-end with a spare
 * number before the official Meta provider is configured. The same
 * warning is shown next to the provider choice in the admin, and in
 * docs/evolution-api-local-setup.md.
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 *
 * Verified before writing (2026-11-21, via web search of
 * doc.evolution-api.com v2, the EvolutionAPI/evolution-api GitHub repo
 * and independent Evolution API references):
 * - POST {base_url}/message/sendText/{instance}
 * - Header: apikey: {api_key}
 * - Body: {"number": "<digits>", "text": "..."} (JSON).
 * - Incoming webhook event "messages.upsert": {"event":
 *   "messages.upsert", "instance": "...", "data": {"key":
 *   {"remoteJid": "2126XXXXXXXX@s.whatsapp.net", "fromMe": false,
 *   "id": "..."}, "message": {"conversation": "..."} or
 *   {"extendedTextMessage": {"text": "..."}}, "pushName": "..."}}.
 * - Instance management: POST /instance/create, GET
 *   /instance/connect/{instance} (QR code), GET
 *   /instance/connectionState/{instance}, POST /webhook/set/{instance}
 *   with {"url": ..., "webhook_by_events": true, "events":
 *   ["MESSAGES_UPSERT", ...]}.
 * - Docker image evoapicloud/evolution-api:v2.3.7 (port 8080), manager
 *   at /manager (evoapicloud/evolution-manager), session volume
 *   /evolution/instances, Postgres + Redis. See
 *   docs/evolution-api-local-setup.md for the exact local setup.
 *
 * NOT verified (no live Evolution server was available, and this
 * project deliberately does not install one — see the task):
 * - Whether /message/sendText accepts a bare digit number or requires
 *   the "@s.whatsapp.net" JID suffix. Most v2 examples send bare
 *   digits; this code sends bare digits. If Evolution answers 400,
 *   retry with the suffix — that fallback is NOT implemented, it is an
 *   open test point (see the doc).
 * - The exact success response shape (message id field). This code
 *   looks for the id in the usual places (key.id, messageId, id) and
 *   tolerates its absence.
 * - Whether /message/sendButtons works reliably over WhatsApp Web.
 *   It exists in the API, but buttons are inconsistently rendered by
 *   WhatsApp Web clients, so this provider deliberately degrades
 *   sendInteractive() to numbered text ("1=confirm, 2=postpone,
 *   3=cancel") — the customer answers with a digit or a word, and the
 *   webhook interprets both. That difference vs Meta is documented in
 *   the admin and in docs/evolution-api-local-setup.md.
 * - Evolution v2 has no documented webhook signature, so the inbound
 *   endpoint cannot verify the sender cryptographically. It only
 *   accepts events whose `instance` matches the configured instance
 *   name; put the endpoint behind a reverse proxy / IP allow-list in
 *   production test setups.
 *
 * Settings come only from the admin
 * (general.cod_system.whatsapp.evolution_*): server base URL, API key
 * (password field), instance name. The API key is never logged.
 */
class EvolutionWhatsAppProvider implements WhatsAppProviderInterface
{
    public const CONFIG_KEY = 'general.cod_system.whatsapp';

    public function name(): string
    {
        return 'evolution';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getBaseUrl())
            && ! empty($this->getApiKey())
            && ! empty($this->getInstance());
    }

    public function getBaseUrl(): ?string
    {
        $url = core()->getConfigData(self::CONFIG_KEY.'.evolution_base_url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    public function getApiKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY.'.evolution_api_key');

        return $key ? (string) $key : null;
    }

    public function getInstance(): ?string
    {
        $instance = core()->getConfigData(self::CONFIG_KEY.'.evolution_instance');

        return $instance ? (string) $instance : null;
    }

    public function sendText(string $phone, string $text): array
    {
        return $this->postSendText($this->formatNumber($phone), $text);
    }

    /**
     * Evolution over WhatsApp Web does not render template quick-reply
     * buttons reliably, so interactive buttons degrade to numbered
     * text: the options are appended as "1- ... / 2- ... / 3- ..." and
     * the customer answers with a digit (or the words). The webhook
     * interprets both. Documented difference vs the Meta provider.
     */
    public function sendInteractive(string $phone, string $text, array $buttons): array
    {
        $lines = [$text, ''];

        foreach (array_slice($buttons, 0, 3) as $index => $button) {
            $lines[] = ($index + 1).'- '.(string) $button['title'];
        }

        return $this->postSendText($this->formatNumber($phone), implode("\n", $lines));
    }

    /**
     * Evolution has no template concept (templates are a Meta
     * Business Platform feature). Callers use sendText() instead.
     */
    public function sendTemplate(string $phone, string $templateName, string $language, array $parameters): array
    {
        return ['success' => false, 'message' => 'Evolution API has no template messages; use plain text instead.', 'message_id' => null];
    }

    /**
     * Evolution takes the number as bare international digits
     * (e.g. 2126XXXXXXXX). Stored numbers may be 06..., +2126... or
     * 2126... — normalize to digits, then swap a leading 0 for 212.
     * See the class docblock: the "@s.whatsapp.net" suffix variant is
     * an unverified fallback, not sent here.
     */
    protected function formatNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '212'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '212'.$digits;
        }

        return $digits;
    }

    protected function postSendText(string $number, string $text): array
    {
        $baseUrl = $this->getBaseUrl();
        $apiKey = $this->getApiKey();
        $instance = $this->getInstance();

        if (empty($baseUrl) || empty($apiKey) || empty($instance)) {
            return ['success' => false, 'message' => 'Evolution API is not configured (server URL, API key or instance missing).', 'message_id' => null];
        }

        try {
            $response = Http::withHeaders(['apikey' => $apiKey])
                ->acceptJson()
                ->timeout(15)
                ->retry(1, 500, fn ($exception) => $exception instanceof ConnectionException)
                ->post($baseUrl.'/message/sendText/'.urlencode($instance), [
                    'number' => $number,
                    'text' => $text,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('COD WhatsApp (Evolution): connection failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Connection to the Evolution API server failed: '.$e->getMessage(), 'message_id' => null];
        }

        $body = $this->safeBody($response);

        if ($response->successful()) {
            $messageId = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? null;

            return ['success' => true, 'message' => 'Message sent via Evolution API.', 'message_id' => is_string($messageId) ? $messageId : null];
        }

        $errorMessage = is_string($body['message'] ?? null) ? $body['message'] : 'HTTP '.$response->status();

        Log::warning('COD WhatsApp (Evolution): send failed', ['status' => $response->status(), 'error' => $errorMessage]);

        return ['success' => false, 'message' => "Evolution API error (HTTP {$response->status()}): {$errorMessage}", 'message_id' => null];
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
