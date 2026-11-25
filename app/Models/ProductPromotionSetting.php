<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

/**
 * Whether one product may be offered as a free gift (cart promotions),
 * show the low-stock badge, and count towards the buy-2-get-3rd-half
 * offer. A product WITHOUT a row participates in all three whenever
 * the other conditions hold (price within a gift tier cap, stock at or
 * under the low-stock threshold, ...). A row with a flag false means
 * Simo switched that offer off for the product from the admin product
 * edit page.
 */
class ProductPromotionSetting extends Model
{
    protected $table = 'product_promotion_settings';

    protected $fillable = [
        'product_id',
        'eligible_as_gift',
        'show_low_stock_badge',
        'eligible_buy2_half',
    ];

    protected $casts = [
        'eligible_as_gift' => 'boolean',
        'show_low_stock_badge' => 'boolean',
        'eligible_buy2_half' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
