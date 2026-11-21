<?php

namespace App\Cod\WhatsApp;

/**
 * WhatsApp settings for the COD System.
 *
 * The store's WhatsApp number is read only from the existing admin
 * WhatsApp settings (general.general.whatsapp.phone_number). It is never
 * hard-coded here and never stored anywhere else. No WhatsApp provider
 * is wired in phase 1, so sending is not possible yet: sendMessage()
 * reports that honestly instead of pretending the message went out.
 */
interface WhatsAppServiceInterface
{
    /**
     * Whether the WhatsApp integration is switched on in the admin.
     */
    public function isEnabled(): bool;

    /**
     * The store WhatsApp number from the admin settings, or null when
     * none is set.
     */
    public function getPhoneNumber(): ?string;

    /**
     * Send a WhatsApp message. Phase 1 has no provider wired, so this
     * always reports not-configured; the real sending arrives with the
     * WhatsApp module in a later phase.
     *
     * @return array{success: bool, message: string}
     */
    public function sendMessage(string $phone, string $message): array;
}
