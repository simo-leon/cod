<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Promotions\PromotionServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Product\Models\Product;

/**
 * Per-product offer toggles (cart promotions + the two new store
 * ideas): Simo switches a product in or out of the free-gift offers,
 * the low-stock badge and the buy-2-get-3rd-half offer from the admin
 * product edit page. A product without a row participates in all
 * three (whenever the other conditions hold).
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
        ]);

        $promotions = app(PromotionServiceInterface::class);

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

        return back()->with('success', trans('admin::app.cod.promotions.product-card.saved'));
    }
}
