<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One WhatsApp conversation tracked by the COD System.
 *
 * Two kinds of conversation live here:
 * - a reply about an existing pending COD order (cod_order_id set,
 *   product_sku empty): the confirmation flow;
 * - a product inquiry with no order yet (product_sku set once the
 *   product is identified, cod_order_id set once the order is
 *   created): the AI collects name, city, address and phone, shows a
 *   summary, and creates the order on confirmation.
 *
 * The actual messages live in cod_whatsapp_messages and are matched
 * by phone (see messages()); this table only tracks the flow state.
 * `collected` holds the product (sku, name, price), the answers
 * (name, city, address, phone) and flow counters (unclear_attempts).
 */
class CodConversation extends Model
{
    public const STATE_COLLECTING_INFO = 'collecting_info';

    public const STATE_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_CANCELED = 'canceled';

    public const STATE_ESCALATED = 'escalated';

    public const STATES = [
        self::STATE_COLLECTING_INFO,
        self::STATE_AWAITING_CONFIRMATION,
        self::STATE_CONFIRMED,
        self::STATE_CANCELED,
        self::STATE_ESCALATED,
    ];

    /**
     * The four fields collected from a customer who inquired about a
     * product, in the order they are asked (from the admin settings).
     */
    public const COLLECT_FIELDS = ['name', 'city', 'address', 'phone'];

    protected $fillable = [
        'phone',
        'cod_order_id',
        'product_sku',
        'state',
        'collected',
        'ai_enabled',
        'escalated_at',
        'last_message_at',
    ];

    protected $casts = [
        'collected' => 'array',
        'ai_enabled' => 'boolean',
        'escalated_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function codOrder(): BelongsTo
    {
        return $this->belongsTo(CodOrder::class);
    }

    /**
     * Every WhatsApp message of this conversation, oldest first: the
     * messages table has no conversation id, messages are matched by
     * the normalized phone number. M21: when the conversation is
     * linked to one order, only that order's messages are shown —
     * another order of the same customer used to leak into the thread.
     */
    public function messages(): HasMany
    {
        $relation = $this->hasMany(CodWhatsAppMessage::class, 'phone', 'phone');

        if ($this->cod_order_id) {
            $relation->where('cod_order_id', $this->cod_order_id);
        }

        return $relation->oldest();
    }

    /**
     * The collected product, if any: ['sku', 'name', 'price'].
     */
    public function product(): ?array
    {
        $product = $this->collected['product'] ?? null;

        return is_array($product) ? $product : null;
    }

    /**
     * The collected answers, keyed by field (name, city, address,
     * phone). Missing answers are absent, not empty.
     */
    public function answers(): array
    {
        $answers = $this->collected['answers'] ?? [];

        return is_array($answers) ? $answers : [];
    }

    /**
     * The first collection field without an answer yet, or null when
     * all four are collected.
     */
    public function nextField(): ?string
    {
        $answers = $this->answers();

        foreach (self::COLLECT_FIELDS as $field) {
            if (empty($answers[$field])) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Whether the conversation was handed to a human (inbox button or
     * AI escalation): the agent must stop replying.
     */
    public function isEscalated(): bool
    {
        return ! $this->ai_enabled || $this->state === self::STATE_ESCALATED;
    }
}
