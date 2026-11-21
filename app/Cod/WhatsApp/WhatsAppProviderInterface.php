<?php

namespace App\Cod\WhatsApp;

/**
 * Contract every WhatsApp provider must implement (COD System phase 4).
 *
 * Providers never read admin config themselves for credentials — the
 * concrete classes read their own config keys (see each class), but the
 * caller (WhatsAppService) decides which provider to use. All methods
 * return ['success' => bool, 'message' => string, 'message_id' => ?string]
 * and never throw: a failed send is a result, not an exception, so a
 * provider outage can never break checkout, assignment or a webhook.
 */
interface WhatsAppProviderInterface
{
    /**
     * Provider key as stored in the admin setting
     * general.cod_system.whatsapp.provider ('meta' | 'evolution' | ...).
     */
    public function name(): string;

    /**
     * Whether the provider has everything it needs to send (enabled in
     * the admin and credentials present). When false, WhatsAppService
     * falls back to the log provider instead of calling the API.
     */
    public function isConfigured(): bool;

    /**
     * Send a plain text message.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendText(string $phone, string $text): array;

    /**
     * Send a text message with quick-reply buttons. Each button is
     * ['id' => string, 'title' => string]. Providers that cannot render
     * real buttons (Evolution over WhatsApp Web) must degrade to
     * numbered text instead of failing — see EvolutionWhatsAppProvider.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendInteractive(string $phone, string $text, array $buttons): array;

    /**
     * Send an approved template message. $parameters are the template
     * body parameters in order ({{1}}, {{2}}, ...). Only Meta supports
     * templates; other providers must return a failure explaining that.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendTemplate(string $phone, string $templateName, string $language, array $parameters): array;
}
