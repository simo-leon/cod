<?php

namespace App\Listeners;

use App\Services\Promotions\PromotionServiceInterface;
use Webkul\Checkout\Contracts\Cart as CartContract;

class SyncBuy2Discount
{
    /**
     * Recompute the buy-2-get-3rd-half discount on the cart.
     *
     * Runs on checkout.cart.calculate.items.tax.before — AFTER the
     * cart rules reset the item discounts (in the collect-totals
     * before event) and BEFORE the tax calc and the totals
     * summation — so the discount flows into tax, the cart totals
     * and the order. It must NOT run in the before event: the
     * CartRule listener (registered in CartRuleServiceProvider,
     * which boots after AppServiceProvider) resets every item
     * discount_amount there and would wipe it.
     */
    public function handle(CartContract $cart): void
    {
        app(PromotionServiceInterface::class)->syncBuy2Discount($cart);
    }
}
