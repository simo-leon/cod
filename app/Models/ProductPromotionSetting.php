<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

/**
 * Whether one product may be offered as a free gift (cart promotions),
 * show the low-stock badge, count towards the buy-2-get-3rd-half
 * offer, show the "Frequently bought together" section on its page,
 * be suggested as a complementary product, appear in the daily
 * deals, accept customer reviews, and show in order tracking. A
 * product WITHOUT a row participates in all of these whenever the
 * other conditions hold (price within a gift tier cap, stock at or
 * under the low-stock threshold, same category for FBT, ...), except
 * daily deals, which are opt-in: only a product Simo explicitly
 * marked (in_daily_deal = true) appears there. A row with a flag
 * false means Simo switched that offer off for the product from the
 * admin product edit page.
 */
class ProductPromotionSetting extends Model
{
    protected $table = 'product_promotion_settings';

    protected $fillable = [
        'product_id',
        'eligible_as_gift',
        'show_low_stock_badge',
        'eligible_buy2_half',
        'show_fbt',
        'fbt_complementary',
        'in_daily_deal',
        'allow_reviews',
        'show_in_tracking',
    ];

    protected $casts = [
        'eligible_as_gift' => 'boolean',
        'show_low_stock_badge' => 'boolean',
        'eligible_buy2_half' => 'boolean',
        'show_fbt' => 'boolean',
        'fbt_complementary' => 'boolean',
        'in_daily_deal' => 'boolean',
        'allow_reviews' => 'boolean',
        'show_in_tracking' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
