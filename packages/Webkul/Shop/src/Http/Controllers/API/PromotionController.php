<?php

namespace Webkul\Shop\Http\Controllers\API;

use App\Services\Promotions\PromotionServiceInterface;
use Illuminate\Http\JsonResponse;
use Webkul\Checkout\Facades\Cart;
use Webkul\Shop\Http\Resources\CartResource;

/**
 * Cart promotions (free delivery + tiered free gifts): add the chosen
 * free gift to the cart. The gift is validated server-side against
 * the admin thresholds and the per-product toggle.
 */
class PromotionController extends APIController
{
    public function __construct(
        protected PromotionServiceInterface $promotions,
    ) {}

    /**
     * Add the chosen free gift to the cart at 0 DH.
     */
    public function storeGift(): JsonResponse
    {
        $validated = $this->validate(request(), [
            'product_id' => 'required|integer|exists:products,id',
            'tier' => 'required|integer|min:1|max:3',
        ]);

        $cart = Cart::getCart();

        if (! $cart) {
            return response()->json([
                'message' => trans('shop::promotions.empty-cart'),
            ], 422);
        }

        try {
            $this->promotions->addGift($cart, (int) $validated['product_id'], (int) $validated['tier']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        Cart::collectTotals();

        $cart = Cart::getCart();

        return response()->json([
            'data' => new CartResource($cart),
            'message' => trans('shop::promotions.gift-added'),
        ]);
    }
}
