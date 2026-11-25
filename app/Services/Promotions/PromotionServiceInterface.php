<?php

namespace App\Services\Promotions;

use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartItem as CartItemContract;
use Webkul\Product\Contracts\Product;

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

    /**
     * Is the buy-2-get-3rd-half offer enabled (admin switch)?
     */
    public function isBuy2Enabled(): bool;

    /**
     * Discount percent applied to the cheapest unit of each buy-2
     * group (0-100, default 50).
     */
    public function buy2DiscountPercent(): float;

    /**
     * Units per buy-2 group (default 3: buy 2, the 3rd is discounted).
     */
    public function buy2GroupSize(): int;

    /**
     * The buy-2 discount for the cart: per-item amounts plus totals.
     * Units are the eligible non-gift units (price > 0, product
     * toggled on); sorted cheapest first, the cheapest unit of each
     * group of buy2GroupSize() units gets buy2DiscountPercent() off.
     *
     * Returns ['items' => [itemId => ['discount' => x,
     * 'base_discount' => y]], 'total' => x, 'base_total' => y,
     * 'units' => n, 'discounts_earned' => n].
     */
    public function buy2Discounts(?CartContract $cart): array;

    /**
     * The buy-2 discount amount (in cart currency) of one cart item,
     * 0 when the item has none.
     */
    public function buy2DiscountForItem(int $itemId): float;

    /**
     * Enforce the buy-2 discount on the cart: add the computed
     * discount to each item's discount_amount (on top of any cart
     * rule discount), capped at the item total. Runs on
     * checkout.cart.calculate.items.tax.before — after the cart
     * rules reset the item discounts and before the tax calc and
     * the totals summation — so the discount flows into tax, the
     * cart totals and the order. Never dispatches cart events.
     */
    public function syncBuy2Discount(?CartContract $cart): void;

    /**
     * The buy-2 payload for the cart API (CartResource).
     */
    public function buy2Array(?CartContract $cart): array;

    /**
     * Is the low-stock badge enabled (admin switch)?
     */
    public function isLowStockEnabled(): bool;

    /**
     * Stock at or under which the low-stock badge shows (default 5).
     */
    public function lowStockThreshold(): int;

    /**
     * Low-stock badge state for one product: ['show' => bool,
     * 'qty' => int]. Only simple products that manage stock and are
     * saleable can show it, only when 0 < qty <= threshold, and
     * only when Simo did not switch the badge off for the product.
     */
    public function lowStockForProduct(Product $product): array;

    /**
     * May the low-stock badge show for this product? A product
     * without a promotion row shows it; Simo hides products from
     * the admin product edit page.
     */
    public function showLowStockBadge(int $productId): bool;

    /**
     * Set the per-product low-stock badge flag (admin product edit page).
     */
    public function setShowLowStockBadge(int $productId, bool $show): void;

    /**
     * May this product count towards the buy-2 groups? A product
     * without a promotion row counts; Simo excludes products from
     * the admin product edit page.
     */
    public function isEligibleBuy2(int $productId): bool;

    /**
     * Set the per-product buy-2 eligibility (admin product edit page).
     */
    public function setEligibleBuy2(int $productId, bool $eligible): void;
}
