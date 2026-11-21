<?php

namespace App\Cod\WhatsApp;

class WhatsAppService implements WhatsAppServiceInterface
{
    /**
     * Whether the WhatsApp integration is switched on in the admin.
     */
    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData('general.general.whatsapp.status');
    }

    /**
     * The store WhatsApp number from the admin settings, or null when
     * none is set.
     */
    public function getPhoneNumber(): ?string
    {
        $number = core()->getConfigData('general.general.whatsapp.phone_number');

        return $number ? (string) $number : null;
    }

    /**
     * Send a WhatsApp message. Phase 1 has no provider wired, so this
     * always reports not-configured; the real sending arrives with the
     * WhatsApp module in a later phase.
     *
     * @return array{success: bool, message: string}
     */
    public function sendMessage(string $phone, string $message): array
    {
        return [
            'success' => false,
            'message' => 'No WhatsApp provider is configured yet.',
        ];
    }
}
