<?php

namespace App\Cod\WhatsApp;

use App\Models\CodOrder;

/**
 * WhatsApp for the COD System (phase 4).
 *
 * The store's WhatsApp number is read only from the existing admin
 * WhatsApp settings (general.general.whatsapp.phone_number). It is never
 * hard-coded here and never stored anywhere else.
 *
 * Sending goes through one of the providers behind
 * WhatsAppProviderInterface (Meta Cloud API for production, Evolution
 * API for testing only, log provider when nothing is configured).
 * Nothing is ever sent while no provider is configured: the log
 * provider only writes to the Laravel log.
 */
interface WhatsAppServiceInterface
{
    /**
     * Whether COD WhatsApp is switched on in the admin
     * (general.cod_system.whatsapp.status).
     */
    public function isEnabled(): bool;

    /**
     * The store WhatsApp number from the admin settings, or null when
     * none is set.
     */
    public function getPhoneNumber(): ?string;

    /**
     * The selected provider key ('meta' | 'evolution' | 'none').
     */
    public function providerName(): string;

    /**
     * Send a plain WhatsApp message through the selected provider.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendMessage(string $phone, string $message): array;

    /**
     * Send the order-confirmation message for one COD order (template
     * from the admin, placeholders filled). Sends at most once per
     * order: a second call returns success with "already sent" unless
     * the first attempt failed. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendConfirmation(CodOrder $order): array;

    /**
     * Send the reminder message for one COD order (reminder template
     * from the admin). Used by `cod:remind-unconfirmed`. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendReminder(CodOrder $order): array;

    /**
     * Normalize a phone number to bare international digits
     * (e.g. 06... or +2126... become 2126...).
     */
    public function normalizePhone(string $phone): string;

    /**
     * Interpret a customer reply as a confirmation outcome:
     * 'confirmed' | 'postponed' | 'canceled', or null when the text
     * means none of those. The LLM classifier (when enabled and
     * configured in COD System → AI) is asked first; when it is
     * disabled, unconfigured or fails, the built-in keyword matcher
     * below is used instead. A 'question' or 'unclear' answer from
     * the LLM also returns null: the order status does not change.
     * Understands digits (1/2/3, Evolution numbered text), Arabic,
     * French and English words.
     */
    public function interpretReply(?string $text): ?string;

    /**
     * Handle one inbound customer message (from either webhook):
     * find the matching pending order by phone, interpret the reply,
     * update the order status, log a call-log entry and the message
     * itself. Never throws; returns ['matched' => bool, ...].
     */
    public function processInbound(string $provider, string $phone, ?string $text, ?string $buttonId = null, ?string $externalMessageId = null): array;
}
