<?php

namespace App\Services\Promotions;

use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartItem as CartItemContract;

/**
 * Cart promotions: free-delivery threshold + tiered free gifts
 * (fouprix-style). Every amount is admin-configurable
 * (general.promotions.settings.*); nothing is hard-coded.
 */
interface PromotionServiceInterface
{
    /**
     * Is the module enabled (admin switch)?
     */
    public function isEnabled(): bool;

    /**
     * Free-delivery threshold in DH (0 = disabled).
     */
    public function freeDeliveryThreshold(): float;

    /**
     * Configured gift tiers: [['tier' => 1, 'threshold' => 400,
     * 'max_price' => 50], ...], sorted by threshold. A tier whose
     * threshold is 0 is disabled and never listed.
     */
    public function giftTiers(): array;

    /**
     * Non-gift subtotal of the cart (base currency, DH).
     */
    public function cartSubtotal(?CartContract $cart): float;

    /**
     * Is delivery free for this cart?
     */
    public function isFreeDelivery(?CartContract $cart): bool;

    /**
     * First unlocked tier without a gift yet (the picker tier), or null.
     */
    public function currentTier(?CartContract $cart): ?array;

    /**
     * First locked tier (progress target), or null when every tier
     * is unlocked.
     */
    public function nextTier(?CartContract $cart): ?array;

    /**
     * The gift cart item of one tier, if the cart already has it.
     */
    public function giftItemForTier(?CartContract $cart, int $tier): ?CartItemContract;

    /**
     * Products the customer may pick as the free gift of one tier:
     * active, simple, saleable, priced at or under the tier cap,
     * cheapest first — never products Simo excluded on the product
     * edit page, never products already gifted in this cart.
     */
    public function eligibleGifts(array $tier, ?CartContract $cart, int $limit = 12): array;

    /**
     * May this product be offered as a free gift? A product without
     * a promotion row is eligible; Simo excludes products from the
     * admin product edit page.
     */
    public function isEligibleAsGift(int $productId): bool;

    /**
     * Set the per-product gift eligibility (admin product edit page).
     */
    public function setEligibleAsGift(int $productId, bool $eligible): void;

    /**
     * Add the chosen gift to the cart at 0 DH, marked as a gift of
     * the tier. Throws an exception when the tier is not unlocked,
     * the product is not eligible, or the tier already has a gift.
     */
    public function addGift(CartContract $cart, int $productId, int $tier): CartItemContract;

    /**
     * Enforce the rules on the cart: drop gift items whose tier is
     * no longer unlocked, whose product was excluded or went out of
     * stock, force gift quantity to 1 and gift prices to 0, and zero
     * the selected flat-rate shipping price when delivery is free.
     * Called from the collect-totals event; never dispatches cart
     * events itself.
     */
    public function syncCart(?CartContract $cart): void;

    /**
     * The promotions payload for the cart API (CartResource).
     */
    public function toArray(?CartContract $cart): array;
}
