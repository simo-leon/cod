<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

/**
 * One souk sourcing job: a product (and quantity) that has to be
 * bought in the souk, brought to the warehouse and checked by the
 * warehouse controller.
 *
 * Statuses (see the migration for the full description):
 * assigned → claimed → purchased → received → verified → paid.
 * A rejected task is terminal; the service creates a fresh assigned
 * task for the same product so another buyer can take it. An
 * expired task is terminal too; the service creates a fresh assigned
 * task so the product stays available. A canceled task is terminal
 * and creates nothing.
 */
class SoukTask extends Model
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PURCHASED = 'purchased';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_CLAIMED,
        self::STATUS_PURCHASED,
        self::STATUS_RECEIVED,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
    ];

    /**
     * Statuses in which the task is still live: one live task per
     * product at most, enforced by the service.
     */
    public const OPEN_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_CLAIMED,
        self::STATUS_PURCHASED,
        self::STATUS_RECEIVED,
        self::STATUS_VERIFIED,
    ];

    protected $fillable = [
        'product_id',
        'cod_order_id',
        'qty',
        'status',
        'buyer_id',
        'controller_id',
        'sale_price',
        'purchase_price',
        'over_cap',
        'below_margin',
        'override_approved',
        'is_escalated',
        'escalated_at',
        'deadline_at',
        'claimed_at',
        'purchased_at',
        'received_at',
        'verified_at',
        'paid_at',
        'rejection_reason',
        'note',
        'cancel_reason',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'over_cap' => 'boolean',
        'below_margin' => 'boolean',
        'is_escalated' => 'boolean',
        'escalated_at' => 'datetime',
        'deadline_at' => 'datetime',
        'claimed_at' => 'datetime',
        'purchased_at' => 'datetime',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function codOrder(): BelongsTo
    {
        return $this->belongsTo(CodOrder::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'buyer_id');
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'controller_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SoukPayment::class)->latest('paid_at');
    }

    /**
     * Whether the task is still live (not terminal).
     */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Whether the task is past its claim deadline without a buyer.
     */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ASSIGNED
            && $this->deadline_at !== null
            && $this->deadline_at->isPast();
    }

    /**
     * Total amount recorded as paid for this task, in DH.
     */
    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Amount still owed to the buyer for the purchase, in DH: the
     * purchase price times the quantity, minus what was already
     * recorded as paid. Null when no purchase price is recorded yet.
     */
    public function amountDue(): ?float
    {
        if ($this->purchase_price === null) {
            return null;
        }

        return round((float) $this->purchase_price * (int) $this->qty - $this->totalPaid(), 2);
    }
}
