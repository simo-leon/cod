<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AbandonedCart\AbandonedCartServiceInterface;
use App\Services\Promotions\PromotionServiceInterface;
use App\Services\StoreFeatures\StoreFeatureServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\ResponseCache\Facades\ResponseCache;
use Webkul\Product\Models\Product;

/**
 * Per-product offer toggles (cart promotions + the store ideas):
 * Simo switches a product in or out of the free-gift offers, the
 * low-stock badge, the buy-2-get-3rd-half offer, the "Frequently
 * bought together" section (as a main product and as a complementary
 * product), the daily deals, customer reviews, order tracking,
 * and the abandoned-cart WhatsApp reminders, from the admin
 * product edit page. A product without a row participates in
 * all of these (whenever the other conditions hold), except the
 * daily deals, which are opt-in.
 */
class PromotionProductController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        $data = $request->validate([
            'eligible_as_gift' => ['required', 'boolean'],
            'show_low_stock_badge' => ['required', 'boolean'],
            'eligible_buy2_half' => ['required', 'boolean'],
            'show_fbt' => ['required', 'boolean'],
            'fbt_complementary' => ['required', 'boolean'],
            'in_daily_deal' => ['required', 'boolean'],
            'allow_reviews' => ['required', 'boolean'],
            'show_in_tracking' => ['required', 'boolean'],
            'include_in_abandoned_cart_reminder' => ['required', 'boolean'],
        ]);

        $promotions = app(PromotionServiceInterface::class);
        $features = app(StoreFeatureServiceInterface::class);
        $abandoned = app(AbandonedCartServiceInterface::class);

        $promotions->setEligibleAsGift(
            (int) $product->id,
            (bool) $data['eligible_as_gift'],
        );

        $promotions->setShowLowStockBadge(
            (int) $product->id,
            (bool) $data['show_low_stock_badge'],
        );

        $promotions->setEligibleBuy2(
            (int) $product->id,
            (bool) $data['eligible_buy2_half'],
        );

        $promotions->setShowFbt(
            (int) $product->id,
            (bool) $data['show_fbt'],
        );

        $promotions->setFbtComplementary(
            (int) $product->id,
            (bool) $data['fbt_complementary'],
        );

        $promotions->setInDailyDeal(
            (int) $product->id,
            (bool) $data['in_daily_deal'],
        );

        $features->setAllowReviews(
            (int) $product->id,
            (bool) $data['allow_reviews'],
        );

        $features->setShowInTracking(
            (int) $product->id,
            (bool) $data['show_in_tracking'],
        );

        $abandoned->setIncludeInReminder(
            (int) $product->id,
            (bool) $data['include_in_abandoned_cart_reminder'],
        );

        /*
         * The toggles change cached storefront pages: the product's
         * own page (FBT section, low-stock badge), the homepage
         * (daily deals), and — for a complementary product — the FBT
         * sections of every other product in its categories. No
         * single URL covers all of those, so the whole response
         * cache is cleared, exactly like a core-config save does
         * (Webkul\FPC\Listeners\CoreConfig). Without this the
         * storefront would keep the old sections for up to 7 days.
         */
        ResponseCache::clear();

        return back()->with('success', trans('admin::app.cod.promotions.product-card.saved'));
    }
}
