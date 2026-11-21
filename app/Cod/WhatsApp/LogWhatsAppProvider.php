<?php

namespace App\Cod\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Fake provider used when no real WhatsApp provider is configured
 * (provider = none, or credentials missing). It never sends anything:
 * it writes the message to the Laravel log and reports success, so the
 * confirmation and reminder flows can be exercised end-to-end without
 * a token and without ever sending a real WhatsApp message. The
 * message log still records the attempt with provider "log" and status
 * "logged" so a test run is distinguishable from a real send in the
 * audit trail.
 */
class LogWhatsAppProvider implements WhatsAppProviderInterface
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function sendText(string $phone, string $text): array
    {
        Log::info('COD WhatsApp (log provider — no message sent)', [
            'to' => $phone,
            'text' => $text,
        ]);

        return ['success' => true, 'message' => 'Logged only — no WhatsApp provider is configured, so nothing was sent.', 'message_id' => null];
    }

    public function sendInteractive(string $phone, string $text, array $buttons): array
    {
        Log::info('COD WhatsApp (log provider — no message sent)', [
            'to' => $phone,
            'text' => $text,
            'buttons' => $buttons,
        ]);

        return ['success' => true, 'message' => 'Logged only — no WhatsApp provider is configured, so nothing was sent.', 'message_id' => null];
    }

    public function sendTemplate(string $phone, string $templateName, string $language, array $parameters): array
    {
        Log::info('COD WhatsApp (log provider — no message sent)', [
            'to' => $phone,
            'template' => $templateName,
            'language' => $language,
            'parameters' => $parameters,
        ]);

        return ['success' => true, 'message' => 'Logged only — no WhatsApp provider is configured, so nothing was sent.', 'message_id' => null];
    }
}
