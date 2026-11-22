<?php

namespace App\Cod\Ai;

use App\Models\CodConversation;

/**
 * The conversational AI agent ("like sat"): it answers the customer
 * like a human, not like a bot.
 *
 * Unlike the reply classifier (one word: confirmed / postponed /
 * canceled / question / unclear), the agent generates a natural
 * Darija reply that persuades the customer, using the FULL
 * conversation history as context (every cod_whatsapp_messages row
 * for the phone, in order) plus the true COD facts (order, product,
 * collected answers) and the full prompt from the admin settings.
 * It also classifies the intent of the latest message, extracts a
 * collected field when the customer gave one, and decides whether
 * the conversation must be escalated to a human.
 *
 * The agent NEVER throws: when the AI is disabled, unconfigured or
 * fails, generate() returns null and the caller falls back to the
 * classifier + the canned confirmation / reminder messages.
 */
interface ConversationAgentInterface
{
    public const INTENT_CONFIRM = 'confirm';

    public const INTENT_POSTPONE = 'postpone';

    public const INTENT_CANCEL = 'cancel';

    public const INTENT_QUESTION = 'question';

    public const INTENT_COLLECT_INFO = 'collect_info';

    public const INTENT_ESCALATE = 'escalate';

    public const INTENT_UNCLEAR = 'unclear';

    public const INTENTS = [
        self::INTENT_CONFIRM,
        self::INTENT_POSTPONE,
        self::INTENT_CANCEL,
        self::INTENT_QUESTION,
        self::INTENT_COLLECT_INFO,
        self::INTENT_ESCALATE,
        self::INTENT_UNCLEAR,
    ];

    /**
     * Whether the agent can answer: the automation is on, the AI is
     * on (COD System → AI) and the AI is configured (base URL + API
     * key + model).
     */
    public function isEnabled(): bool;

    /**
     * Whether the AI is configured (base URL + API key + model set).
     */
    public function isConfigured(): bool;

    /**
     * Generate the agent's answer to the latest customer message.
     *
     * $facts carries the true data the agent may use (and nothing
     * else): 'order' => ?CodOrder, 'product' => ?array (sku, name,
     * price), 'next_field' => ?string, 'collected' => array.
     *
     * @return ?array{reply: string, intent: string, field: ?string, value: ?string, escalate: bool, summary: ?string}
     *                                                                                                                 or null when the agent is unavailable (disabled,
     *                                                                                                                 unconfigured or any failure). Never throws.
     */
    public function generate(CodConversation $conversation, string $incomingText, array $facts = []): ?array;
}
