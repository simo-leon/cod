<?php

namespace App\Cod\WhatsApp;

use App\Models\CodCallLog;
use App\Models\CodOrder;
use App\Models\CodWhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp for the COD System (phase 4).
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
 */
class WhatsAppService implements WhatsAppServiceInterface
{
    public const CONFIG_KEY = 'general.cod_system.whatsapp';

    public const PROVIDER_META = 'meta';

    public const PROVIDER_EVOLUTION = 'evolution';

    public const PROVIDER_NONE = 'none';

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

        $this->logMessage(null, CodWhatsAppMessage::DIRECTION_OUT, $phone, $message, $result);

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
            $order->whatsapp_provider = $provider->name();
            $order->save();
        }

        return $result;
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '212'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '212'.$digits;
        }

        return $digits;
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

        // Evolution numbered text: a leading digit decides (1/2/3).
        if (preg_match('/^([123])[\s\.\-\)]*/u', $clean, $matches)) {
            return match ($matches[1]) {
                '1' => 'confirmed',
                '2' => 'postponed',
                '3' => 'canceled',
            };
        }

        $postponed = ['أجل', 'اجل', 'لاحق', 'بعدين', 'غدا', 'غداً', 'غد', 'delay', 'postpone', 'plus tard', 'demain', 'rappel', 'call me later', 'later'];
        $canceled = ['ألغ', 'الغ', 'إلغاء', 'الغاء', 'cancel', 'annul', 'refus', 'لا أريد', 'لا اريد', 'non merci'];
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
     * Reminder delay in minutes (admin, default 120).
     */
    public function reminderDelayMinutes(): int
    {
        return max(1, (int) (core()->getConfigData(self::CONFIG_KEY.'.reminder_delay_minutes') ?? 120));
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
     * Fill the template placeholders for one order.
     */
    public function renderTemplate(string $template, CodOrder $order): string
    {
        return str_replace(
            ['{order_id}', '{name}', '{total}', '{products}', '{store}'],
            [
                $order->external_id,
                $order->customer_name ?? '',
                $this->formatTotal($order),
                $this->productNames($order),
                (string) config('app.name', 'Jemla En Ligne'),
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
     */
    protected function applyOutcome(CodOrder $order, string $outcome, string $text): void
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
    }

    protected function logMessage(?CodOrder $order, string $direction, string $phone, string $body, array $result, ?string $providerName = null): void
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
