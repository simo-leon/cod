<?php

namespace App\Cod\Ai;

/**
 * Classifies a free-text customer reply into one of the reply intents
 * used by the COD WhatsApp confirmation flow.
 *
 * Intents:
 * - confirmed: the customer confirms the order.
 * - postponed: the customer asks to delay / be called later.
 * - canceled: the customer refuses or cancels the order.
 * - question: the customer asks something (price, delivery, ...) — the
 *   order status must NOT change; the reply is only recorded.
 * - unclear: the reply cannot be understood as any of the above — same
 *   as a question, no status change.
 *
 * A null return means the classifier is UNAVAILABLE (disabled,
 * unconfigured, unreachable provider, unparseable answer). Callers must
 * fall back to the built-in keyword matcher in that case — never treat
 * null as "unclear".
 */
interface ReplyIntentClassifierInterface
{
    public const INTENT_CONFIRMED = 'confirmed';

    public const INTENT_POSTPONED = 'postponed';

    public const INTENT_CANCELED = 'canceled';

    public const INTENT_QUESTION = 'question';

    public const INTENT_UNCLEAR = 'unclear';

    public const INTENTS = [
        self::INTENT_CONFIRMED,
        self::INTENT_POSTPONED,
        self::INTENT_CANCELED,
        self::INTENT_QUESTION,
        self::INTENT_UNCLEAR,
    ];

    /**
     * Whether AI reply understanding is switched on in the admin
     * (general.cod_system.ai.status).
     */
    public function isEnabled(): bool;

    /**
     * Whether the AI is configured (base URL + API key + model set).
     */
    public function isConfigured(): bool;

    /**
     * Classify one customer reply (Darija, Arabic, French, English).
     *
     * @return one of self::INTENTS, or null when the classifier is
     *             unavailable (disabled, unconfigured or any failure).
     *             Never throws.
     */
    public function classify(string $text): ?string;
}
