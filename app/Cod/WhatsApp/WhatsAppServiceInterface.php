<?php

namespace App\Cod\WhatsApp;

use App\Models\CodConversation;
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
     * Whether WhatsApp is on but no real provider can send (H2).
     */
    public function isEnabledWithoutRealProvider(): bool;

    /**
     * Whether an incoming webhook could be forged because no secret
     * protects it (H1).
     */
    public function webhookUnprotected(): bool;

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
     * Send one message in a conversation (AI agent reply or manual
     * inbox reply) through the selected provider, and record it with
     * its sender ('ai' | 'human') so the inbox can tell AI / human /
     * system messages apart. The conversation's linked order is
     * recorded when there is one. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendConversationMessage(CodConversation $conversation, string $text, string $sender): array;

    /**
     * Send the order-confirmation message for one COD order (template
     * from the admin, placeholders filled). Sends at most once per
     * order: a second call returns success with "already sent" unless
     * the first attempt failed. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    /**
     * @param  bool  $force  re-send even when a confirmation was already sent (manual resend button)
     */
    public function sendConfirmation(CodOrder $order, bool $force = false): array;

    /**
     * Send the reminder message for one COD order (reminder template
     * from the admin). Used by `cod:remind-unconfirmed`. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendReminder(CodOrder $order): array;

    /**
     * Send an abandoned-cart reminder to one phone number (idea 7).
     * The message text is rendered by the abandoned-cart service;
     * $templateParams are the Meta template body parameters, in
     * this order: name, products, total. The template must be
     * approved by Meta with exactly these three {{1}}..{{3}}
     * parameters. When no template name is configured
     * (general.cod_system.whatsapp_messages.meta_abandoned_cart_template_name),
     * the text is sent as a plain message (Meta: only inside the
     * 24-hour customer-service window; Evolution: always plain
     * text). When WhatsApp is disabled or no provider is configured,
     * nothing is sent and the failure is returned — the caller must
     * not record the reminder as sent. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendAbandonedCartReminder(string $phone, string $message, array $templateParams = []): array;

    /**
     * Send the customer a delivery-status notification for one COD
     * order (phase 6). $event is one of 'shipped',
     * 'out_for_delivery', 'delivered' or 'returned'; the message
     * template is editable in the admin (COD System → WhatsApp) and
     * supports {order_id} {name} {total} {products} {store} plus
     * {tracking_code}. Sends at most once per event per order: the
     * matching *_notification_sent_at column is set only on a
     * successful send, so a failed send is retried on the next sync
     * and a sent one is never repeated. When WhatsApp is disabled or
     * no provider is configured, nothing is sent and the *_sent_at
     * column stays empty — the notification is simply skipped, and
     * the failure never breaks the tracking sync. Never throws.
     *
     * @return array{success: bool, message: string, message_id: ?string}
     */
    public function sendStatusNotification(CodOrder $order, string $event, ?string $trackingCode = null): array;

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
     *
     * With the WhatsApp automation on (and the cod_conversations
     * table present), the conversation agent answers first (full
     * history + COD facts + the admin prompt); a message with no
     * pending order starts the product-inquiry flow instead, and a
     * conversation handed to a human gets no automatic reply. When
     * the agent is unavailable, the classifier / keyword reply
     * understanding below takes over. With the automation off, the
     * behaviour is exactly the historic one.
     */
    public function processInbound(string $provider, string $phone, ?string $text, ?string $buttonId = null, ?string $externalMessageId = null): array;
}
