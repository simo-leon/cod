<?php

namespace App\Listeners;

use App\Services\Promotions\PromotionServiceInterface;
use Webkul\Checkout\Contracts\Cart as CartContract;

/**
 * Keeps the cart promotions honest on every totals collection:
 * drops gift items whose tier is no longer unlocked, whose product
 * was excluded or went out of stock, forces gift quantity to 1 and
 * gift prices to 0, and zeroes the flat-rate shipping price when
 * delivery is free. Runs on checkout.cart.collect.totals.before;
 * it only touches the database rows directly and never dispatches
 * cart events, so it cannot recurse.
 */
class SyncCartPromotions
{
    public function handle(CartContract $cart): void
    {
        app(PromotionServiceInterface::class)->syncCart($cart);
    }
}
