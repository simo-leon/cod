<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Promotions\PromotionServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Product\Models\Product;

/**
 * Per-product gift eligibility (cart promotions): Simo switches a
 * product in or out of the free-gift offers from the admin product
 * edit page. A product without a row is eligible whenever its price
 * is within the gift tier cap.
 */
class PromotionProductController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        $data = $request->validate([
            'eligible_as_gift' => ['required', 'boolean'],
        ]);

        app(PromotionServiceInterface::class)->setEligibleAsGift(
            (int) $product->id,
            (bool) $data['eligible_as_gift'],
        );

        return back()->with('success', trans('admin::app.cod.promotions.product-card.saved'));
    }
}
