<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

/**
 * Whether one product may be offered as a free gift (cart promotions).
 *
 * A product WITHOUT a row is eligible whenever its price is within
 * the gift tier cap. A row with eligible_as_gift = false means Simo
 * excluded the product from the admin product edit page, so it never
 * appears in the gift picker.
 */
class ProductPromotionSetting extends Model
{
    protected $table = 'product_promotion_settings';

    protected $fillable = [
        'product_id',
        'eligible_as_gift',
    ];

    protected $casts = [
        'eligible_as_gift' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
