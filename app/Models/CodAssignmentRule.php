<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\Admin;

/**
 * One distribution rule for the COD System (phase 3).
 *
 * - Percentage rule (`type = percentage`): the confirmer receives a
 *   share of new orders proportional to `weight` (50 vs 30 vs 20 behaves
 *   like 50% / 30% / 20%). One per confirmer. `current_weight` is the
 *   smooth weighted round-robin state kept by the distributor.
 * - Product rule (`type = product`): every order containing
 *   `product_sku` goes to this confirmer, before percentage rules are
 *   considered. One per confirmer per SKU.
 *
 * Only the confirmation supervisor manages these rules; the confirmer
 * never sees them.
 */
class CodAssignmentRule extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_PRODUCT = 'product';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_PRODUCT,
    ];

    protected $fillable = [
        'type',
        'admin_id',
        'weight',
        'current_weight',
        'product_sku',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'integer',
        'current_weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function isPercentage(): bool
    {
        return $this->type === self::TYPE_PERCENTAGE;
    }

    public function isProduct(): bool
    {
        return $this->type === self::TYPE_PRODUCT;
    }
}
