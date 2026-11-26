<?php

namespace App\Listeners;

use App\Services\Promotions\PromotionServiceInterface;
use Webkul\Checkout\Contracts\Cart as CartContract;

class SyncCouponDiscount
{
    /**
     * Recompute the automatic coupon discount of the unlocked coupon
     * tier on the cart.
     *
     * Runs on checkout.cart.calculate.items.tax.before — AFTER the
     * FBT listener (registered before it in AppServiceProvider)
     * and BEFORE the tax calc and the totals summation — so the
     * discount flows into tax, the cart totals and the order. It
     * must NOT run in the before event: the CartRule listener
     * (CartRuleServiceProvider boots after AppServiceProvider)
     * resets every item discount_amount there and would wipe it.
     */
    public function handle(CartContract $cart): void
    {
        app(PromotionServiceInterface::class)->syncCouponDiscount($cart);
    }
}
