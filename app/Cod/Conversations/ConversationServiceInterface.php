<?php

namespace App\Cod\Conversations;

use App\Models\CodConversation;
use App\Models\CodOrder;

/**
 * The product-inquiry conversation flow: one conversation per customer
 * phone, collecting name, city, address and phone, then creating the
 * real COD order on confirmation. The service owns the flow state
 * (CodConversation); it never sends a WhatsApp message itself.
 */
interface ConversationServiceInterface
{
    /**
     * The conversation for one normalized phone number, created when
     * there is none yet.
     */
    public function findOrCreateByPhone(string $phone): CodConversation;

    /**
     * Start the collection flow for one matched product and return the
     * first question.
     */
    public function startProductInquiry(CodConversation $conversation, array $product): string;

    /**
     * The question for the next missing field, or the summary when
     * everything is collected.
     */
    public function questionFor(CodConversation $conversation): string;

    /**
     * Record one collected answer and return the next question (or the
     * summary when that was the last field).
     */
    public function recordAnswer(CodConversation $conversation, string $field, string $value): string;

    /**
     * The summary shown before confirmation.
     */
    public function summaryText(CodConversation $conversation): string;

    /**
     * Create the real COD order for a confirmed product inquiry, or
     * null when automatic creation is off or the creation fails.
     */
    public function createOrder(CodConversation $conversation): ?CodOrder;

    /**
     * Mark the conversation canceled (the customer refused).
     */
    public function cancel(CodConversation $conversation): void;

    /**
     * Hand the conversation to a human: the AI stops replying.
     */
    public function escalate(CodConversation $conversation, ?string $reason = null): void;

    /**
     * Hand the conversation back to the AI after an escalation.
     */
    public function resumeAi(CodConversation $conversation): void;

    /**
     * Count an unclear reply on the conversation. Returns the new count.
     */
    public function countUnclear(CodConversation $conversation): int;

    /**
     * Reset the unclear counter.
     */
    public function resetUnclear(CodConversation $conversation): void;

    /**
     * Touch the last-message timestamp.
     */
    public function touch(CodConversation $conversation): void;
}
