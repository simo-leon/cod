<?php

namespace App\Services\Promotions;

use App\Models\ProductPromotionSetting;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartItem as CartItemContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartItemRepository;
use Webkul\Product\Contracts\Product as ProductContract;
use Webkul\Product\Facades\ProductImage;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Cart promotions: free-delivery threshold + tiered free gifts
 * (fouprix-style). Every amount comes from the admin
 * (general.promotions.settings.*); nothing is hard-coded.
 *
 * A gift is a normal cart item with custom_price = 0 and additional
 * ['is_gift' => true, 'gift_tier' => N]. The custom price survives
 * the cart validation because the product types respect it (see
 * Simple/AbstractType validateCartItem). The gift is removed
 * automatically when its tier is no longer unlocked, when Simo
 * excludes the product, or when it goes out of stock.
 */
class PromotionService implements PromotionServiceInterface
{
    /**
     * Re-entrancy guard for syncCart: it runs inside collectTotals
     * and must never trigger another totals collection.
     */
    protected static bool $syncing = false;

    /**
     * Re-entrancy guard for syncBuy2Discount (same pattern).
     */
    protected static bool $syncingBuy2 = false;

    /**
     * Memoized buy-2 computation for the current request, keyed by a
     * signature of the cart contents and the promotion settings.
     */
    protected static ?array $buy2Memo = null;

    public function __construct(
        protected CartItemRepository $cartItemRepository,
        protected ProductRepository $productRepository,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.promotions.settings.status') ?? true);
    }

    public function freeDeliveryThreshold(): float
    {
        return max(0, (float) (core()->getConfigData('general.promotions.settings.free_delivery_threshold') ?? 300));
    }

    public function giftTiers(): array
    {
        $tiers = [];

        foreach ([1, 2, 3] as $slot) {
            $threshold = (float) (core()->getConfigData("general.promotions.settings.tier_{$slot}_threshold") ?? 0);

            if ($threshold <= 0) {
                continue;
            }

            $tiers[] = [
                'tier' => $slot,
                'threshold' => $threshold,
                'max_price' => max(0, (float) (core()->getConfigData("general.promotions.settings.tier_{$slot}_max_price") ?? 0)),
            ];
        }

        usort($tiers, fn ($a, $b) => $a['threshold'] <=> $b['threshold']);

        return $tiers;
    }

    public function cartSubtotal(?CartContract $cart): float
    {
        if (! $cart) {
            return 0.0;
        }

        $subtotal = 0.0;

        foreach ($cart->items as $item) {
            if ($this->isGiftItem($item)) {
                continue;
            }

            $subtotal += (float) $item->base_total;
        }

        return round($subtotal, 2);
    }

    public function isFreeDelivery(?CartContract $cart): bool
    {
        if (! $this->isEnabled() || ! $cart) {
            return false;
        }

        $threshold = $this->freeDeliveryThreshold();

        return $threshold > 0 && $this->cartSubtotal($cart) >= $threshold;
    }

    public function currentTier(?CartContract $cart): ?array
    {
        if (! $this->isEnabled() || ! $cart) {
            return null;
        }

        $subtotal = $this->cartSubtotal($cart);

        foreach ($this->giftTiers() as $tier) {
            if ($subtotal < $tier['threshold']) {
                continue;
            }

            if ($this->giftItemForTier($cart, (int) $tier['tier'])) {
                continue;
            }

            return $tier;
        }

        return null;
    }

    public function nextTier(?CartContract $cart): ?array
    {
        if (! $this->isEnabled() || ! $cart) {
            return null;
        }

        $subtotal = $this->cartSubtotal($cart);

        foreach ($this->giftTiers() as $tier) {
            if ($subtotal >= $tier['threshold']) {
                continue;
            }

            $tier['remaining'] = round($tier['threshold'] - $subtotal, 2);

            return $tier;
        }

        return null;
    }

    public function giftItemForTier(?CartContract $cart, int $tier): ?CartItemContract
    {
        if (! $cart) {
            return null;
        }

        foreach ($cart->items as $item) {
            if (
                $this->isGiftItem($item)
                && (int) ($item->additional['gift_tier'] ?? 0) === $tier
            ) {
                return $item;
            }
        }

        return null;
    }

    public function eligibleGifts(array $tier, ?CartContract $cart, int $limit = 12): array
    {
        if (! $this->isEnabled() || (float) ($tier['max_price'] ?? 0) <= 0) {
            return [];
        }

        $cap = (float) $tier['max_price'];
        $locale = core()->getCurrentLocale()->code;
        $channel = core()->getCurrentChannelCode();

        $giftedProductIds = $cart
            ? collect($cart->items)
                ->filter(fn ($item) => $this->isGiftItem($item))
                ->map(fn ($item) => (int) $item->product_id)
                ->all()
            : [];

        $excludedIds = ProductPromotionSetting::query()
            ->where('eligible_as_gift', false)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = DB::table('product_flat')
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->where('status', 1)
            ->where('type', 'simple')
            ->where('price', '>', 0)
            ->where('price', '<=', $cap)
            ->orderBy('price')
            ->limit(40)
            ->get(['product_id', 'name', 'url_key', 'price']);

        $gifts = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            if (
                in_array($productId, $giftedProductIds, true)
                || in_array($productId, $excludedIds, true)
            ) {
                continue;
            }

            $product = $this->productRepository->find($productId);

            if (! $product) {
                continue;
            }

            $type = $product->getTypeInstance();

            if (! $type->isSaleable() || ! $type->haveSufficientQuantity(1)) {
                continue;
            }

            $name = $row->name ?: $this->flatName($productId, 'en');
            $image = ProductImage::getProductBaseImage($product);

            $gifts[] = [
                'id' => $productId,
                'name' => $name ?: $product->name,
                'price' => (float) $row->price,
                'formatted_price' => core()->formatPrice((float) $row->price),
                'image' => $image['medium_image_url'] ?? ($image['original_image_url'] ?? null),
                'url' => route('shop.product_or_category.index', $row->url_key),
            ];

            if (count($gifts) >= $limit) {
                break;
            }
        }

        return $gifts;
    }

    public function isEligibleAsGift(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->eligible_as_gift;
    }

    public function setEligibleAsGift(int $productId, bool $eligible): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['eligible_as_gift' => $eligible],
        );
    }

    public function addGift(CartContract $cart, int $productId, int $tier): CartItemContract
    {
        if (! $this->isEnabled()) {
            throw new \Exception(trans('shop::promotions.disabled'));
        }

        $tiers = collect($this->giftTiers())->keyBy('tier');

        if (! $tiers->has($tier)) {
            throw new \Exception(trans('shop::promotions.invalid-tier'));
        }

        $tierData = $tiers->get($tier);

        if ($this->cartSubtotal($cart) < (float) $tierData['threshold']) {
            throw new \Exception(trans('shop::promotions.tier-locked'));
        }

        if ($this->giftItemForTier($cart, $tier)) {
            throw new \Exception(trans('shop::promotions.already-gifted'));
        }

        $product = $this->productRepository->find($productId);

        if (! $product || $product->type !== 'simple' || ! $product->status) {
            throw new \Exception(trans('shop::promotions.invalid-product'));
        }

        if (! $this->isEligibleAsGift($productId)) {
            throw new \Exception(trans('shop::promotions.not-eligible'));
        }

        $price = (float) $product->getTypeInstance()->getFinalPrice();

        if ($price <= 0 || $price > (float) $tierData['max_price']) {
            throw new \Exception(trans('shop::promotions.over-cap'));
        }

        $type = $product->getTypeInstance();

        if (! $type->isSaleable() || ! $type->haveSufficientQuantity(1)) {
            throw new \Exception(trans('shop::promotions.out-of-stock'));
        }

        $weight = (float) ($product->weight ?? 0);

        $item = $this->cartItemRepository->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity' => 1,
            'name' => $product->name,
            'price' => 0,
            'price_incl_tax' => 0,
            'base_price' => 0,
            'base_price_incl_tax' => 0,
            'custom_price' => 0,
            'total' => 0,
            'total_incl_tax' => 0,
            'base_total' => 0,
            'base_total_incl_tax' => 0,
            'weight' => $weight,
            'total_weight' => $weight,
            'base_total_weight' => $weight,
            'type' => 'simple',
            'tax_category_id' => $product->tax_category_id ?? null,
            'additional' => [
                'product_id' => $product->id,
                'quantity' => 1,
                'is_gift' => true,
                'gift_tier' => $tier,
            ],
        ]);

        Cart::collectTotals();

        return $item;
    }

    public function syncCart(?CartContract $cart): void
    {
        if (! $cart || static::$syncing) {
            return;
        }

        static::$syncing = true;

        try {
            // The cart model may hold a stale items relation: right
            // after Cart::removeItem() the deleted row is gone from
            // the database but still present in the loaded relation,
            // so the subtotal (and the gift list) would be computed
            // on rows that no longer exist. Reload from the DB first.
            $cart->load('items');

            $enabled = $this->isEnabled();
            $subtotal = $this->cartSubtotal($cart);
            $tiers = collect($this->giftTiers())->keyBy('tier');
            $changed = false;

            foreach ($cart->items as $item) {
                if (! $this->isGiftItem($item)) {
                    continue;
                }

                $tierNumber = (int) ($item->additional['gift_tier'] ?? 0);
                $tierData = $tiers->get($tierNumber);
                $remove = false;

                if (! $enabled || ! $tierData || $subtotal < (float) $tierData['threshold']) {
                    $remove = true;
                } elseif (! $this->isEligibleAsGift((int) $item->product_id)) {
                    $remove = true;
                } else {
                    $product = $this->productRepository->find((int) $item->product_id);

                    if (
                        ! $product
                        || ! $product->status
                        || ! $product->getTypeInstance()->isSaleable()
                        || ! $product->getTypeInstance()->haveSufficientQuantity(1)
                    ) {
                        $remove = true;
                    }
                }

                if ($remove) {
                    $this->cartItemRepository->delete((int) $item->id);
                    $changed = true;

                    continue;
                }

                $updates = [];

                if ((int) $item->quantity !== 1) {
                    $updates['quantity'] = 1;
                }

                if (
                    (float) $item->price !== 0.0
                    || (float) $item->base_price !== 0.0
                    || (float) $item->total !== 0.0
                    || (float) $item->base_total !== 0.0
                    || $item->custom_price === null
                    || (float) $item->custom_price !== 0.0
                ) {
                    $updates = array_merge($updates, [
                        'price' => 0,
                        'price_incl_tax' => 0,
                        'base_price' => 0,
                        'base_price_incl_tax' => 0,
                        'custom_price' => 0,
                        'total' => 0,
                        'total_incl_tax' => 0,
                        'base_total' => 0,
                        'base_total_incl_tax' => 0,
                        'tax_amount' => 0,
                        'base_tax_amount' => 0,
                    ]);
                }

                if ($updates) {
                    $this->cartItemRepository->update($updates, (int) $item->id);
                    $changed = true;
                }
            }

            if ($changed) {
                $cart->unsetRelation('items');
                $cart->unsetRelation('all_items');
            }

            $this->syncShippingRate($cart);
        } finally {
            static::$syncing = false;
        }
    }

    public function toArray(?CartContract $cart): array
    {
        $enabled = $this->isEnabled();
        $subtotal = $this->cartSubtotal($cart);
        $freeThreshold = $this->freeDeliveryThreshold();

        $freeRemaining = $freeThreshold > 0 ? max(0, round($freeThreshold - $subtotal, 2)) : 0.0;

        $currentTier = $this->currentTier($cart);
        $nextTier = $this->nextTier($cart);

        if ($nextTier) {
            $nextTier['remaining'] = round($nextTier['threshold'] - $subtotal, 2);
            $nextTier['formatted_remaining'] = core()->formatPrice($nextTier['remaining']);
            $nextTier['formatted_threshold'] = core()->formatPrice($nextTier['threshold']);
            $nextTier['formatted_max_price'] = core()->formatPrice($nextTier['max_price']);
            $nextTier['progress'] = $nextTier['threshold'] > 0
                ? (int) min(100, round($subtotal / $nextTier['threshold'] * 100))
                : 100;
        }

        if ($currentTier) {
            $currentTier['formatted_threshold'] = core()->formatPrice($currentTier['threshold']);
            $currentTier['formatted_max_price'] = core()->formatPrice($currentTier['max_price']);
        }

        $giftedTiers = [];

        if ($enabled && $cart) {
            foreach ($this->giftTiers() as $tier) {
                $giftItem = $this->giftItemForTier($cart, (int) $tier['tier']);

                if (! $giftItem) {
                    continue;
                }

                $image = null;
                $product = $this->productRepository->find((int) $giftItem->product_id);

                if ($product) {
                    $urls = ProductImage::getProductBaseImage($product);
                    $image = $urls['medium_image_url'] ?? ($urls['original_image_url'] ?? null);
                }

                $giftedTiers[] = [
                    'tier' => (int) $tier['tier'],
                    'threshold' => (float) $tier['threshold'],
                    'formatted_threshold' => core()->formatPrice($tier['threshold']),
                    'gift' => [
                        'id' => (int) $giftItem->product_id,
                        'item_id' => (int) $giftItem->id,
                        'name' => $giftItem->name,
                        'image' => $image,
                    ],
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'subtotal' => $subtotal,
            'formatted_subtotal' => core()->formatPrice($subtotal),
            'free_delivery' => [
                'threshold' => $freeThreshold,
                'formatted_threshold' => core()->formatPrice($freeThreshold),
                'remaining' => $freeRemaining,
                'formatted_remaining' => core()->formatPrice($freeRemaining),
                'unlocked' => $enabled && $freeThreshold > 0 && $subtotal >= $freeThreshold,
                'progress' => $freeThreshold > 0
                    ? (int) min(100, round($subtotal / $freeThreshold * 100))
                    : 0,
            ],
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'gifted_tiers' => $giftedTiers,
            'eligible_gifts' => $currentTier ? $this->eligibleGifts($currentTier, $cart) : [],
        ];
    }

    /**
     * Is the buy-2-get-3rd-half offer enabled (admin switch)?
     */
    public function isBuy2Enabled(): bool
    {
        return (bool) (core()->getConfigData('general.promotions.settings.buy2_status') ?? true);
    }

    /**
     * Discount percent applied to the cheapest unit of each buy-2
     * group (0-100, default 50).
     */
    public function buy2DiscountPercent(): float
    {
        return min(100, max(0, (float) (core()->getConfigData('general.promotions.settings.buy2_discount_percent') ?? 50)));
    }

    /**
     * Units per buy-2 group (default 3: buy 2, the 3rd is discounted).
     */
    public function buy2GroupSize(): int
    {
        return max(2, (int) (core()->getConfigData('general.promotions.settings.buy2_group_size') ?? 3));
    }

    /**
     * The buy-2 discount for the cart, memoized per request by a
     * signature of the cart contents (items, quantities, prices,
     * gift flags and promotion settings), so the cart API and the
     * cart item resources all read the same numbers.
     */
    public function buy2Discounts(?CartContract $cart): array
    {
        $empty = ['items' => [], 'total' => 0.0, 'base_total' => 0.0, 'units' => 0, 'discounts_earned' => 0];

        if (! $this->isBuy2Enabled() || ! $cart) {
            return $empty;
        }

        $settings = $this->promotionSettings(
            collect($cart->items)->map(fn ($item) => (int) $item->product_id)->unique()->all()
        );

        $parts = [];

        foreach ($cart->items as $item) {
            $parts[] = implode(':', [
                (int) $item->id,
                (int) $item->product_id,
                (int) $item->quantity,
                (float) $item->price,
                (float) $item->base_price,
                (int) ($item->additional['is_gift'] ?? 0),
            ]);
        }

        $key = (int) $cart->id.':'.md5(implode('|', $parts).'|'.json_encode($settings));

        if (static::$buy2Memo && static::$buy2Memo['key'] === $key) {
            return static::$buy2Memo['value'];
        }

        $units = [];

        foreach ($cart->items as $item) {
            if ($this->isGiftItem($item)) {
                continue;
            }

            if ((float) $item->base_price <= 0 || (int) $item->quantity <= 0) {
                continue;
            }

            if (! $this->isEligibleBuy2Row($settings, (int) $item->product_id)) {
                continue;
            }

            for ($i = 0; $i < (int) $item->quantity; $i++) {
                $units[] = [
                    'item_id' => (int) $item->id,
                    'price' => (float) $item->price,
                    'base_price' => (float) $item->base_price,
                ];
            }
        }

        $groupSize = $this->buy2GroupSize();
        $rate = $this->buy2DiscountPercent() / 100;
        $discountsEarned = (int) floor(count($units) / $groupSize);

        $items = [];
        $total = 0.0;
        $baseTotal = 0.0;

        if ($discountsEarned > 0 && $rate > 0) {
            usort($units, fn ($a, $b) => $a['price'] <=> $b['price'] ?: $a['item_id'] <=> $b['item_id']);

            for ($i = 0; $i < $discountsEarned; $i++) {
                $unit = $units[$i * $groupSize];

                $discount = round($unit['price'] * $rate, 2);
                $baseDiscount = round($unit['base_price'] * $rate, 2);
                $itemId = $unit['item_id'];

                $items[$itemId] = [
                    'discount' => round(($items[$itemId]['discount'] ?? 0) + $discount, 2),
                    'base_discount' => round(($items[$itemId]['base_discount'] ?? 0) + $baseDiscount, 2),
                ];

                $total = round($total + $discount, 2);
                $baseTotal = round($baseTotal + $baseDiscount, 2);
            }
        }

        $result = [
            'items' => $items,
            'total' => $total,
            'base_total' => $baseTotal,
            'units' => count($units),
            'discounts_earned' => $discountsEarned,
        ];

        static::$buy2Memo = ['key' => $key, 'value' => $result];

        return $result;
    }

    /**
     * The buy-2 discount amount (in cart currency) of one cart item,
     * 0 when the item has none.
     */
    public function buy2DiscountForItem(int $itemId): float
    {
        $item = $this->cartItemRepository->find($itemId);

        if (! $item || ! $item->cart) {
            return 0.0;
        }

        $discounts = $this->buy2Discounts($item->cart);

        return (float) ($discounts['items'][$itemId]['discount'] ?? 0.0);
    }

    /**
     * Enforce the buy-2 discount on the cart. Runs on
     * checkout.cart.calculate.items.tax.before — after the cart
     * rules reset the item discounts (in the collect-totals before
     * event) and before the tax calc and the totals summation — so
     * the discount flows into tax, the cart totals and the order.
     * Adds the computed discount on top of any cart rule discount,
     * capped at the item total, and persists it like
     * CartRule::process does. Never dispatches cart events.
     */
    public function syncBuy2Discount(?CartContract $cart): void
    {
        if (! $cart || static::$syncingBuy2) {
            return;
        }

        static::$syncingBuy2 = true;

        try {
            // Same staleness guard as syncCart: reload the items
            // from the database before computing.
            $cart->load('items');

            $discounts = $this->buy2Discounts($cart);

            foreach ($cart->items as $item) {
                $mine = $discounts['items'][(int) $item->id] ?? null;

                $target = (float) $item->discount_amount;
                $baseTarget = (float) $item->base_discount_amount;

                if ($mine) {
                    $target = min(
                        round($target + $mine['discount'], 2),
                        round((float) $item->price * (int) $item->quantity, 2)
                    );

                    $baseTarget = min(
                        round($baseTarget + $mine['base_discount'], 2),
                        round((float) $item->base_price * (int) $item->quantity, 2)
                    );
                }

                if (
                    abs($target - (float) $item->discount_amount) > 0.004
                    || abs($baseTarget - (float) $item->base_discount_amount) > 0.004
                ) {
                    $item->discount_amount = $target;
                    $item->base_discount_amount = $baseTarget;
                    $item->save();
                }
            }
        } finally {
            static::$syncingBuy2 = false;
        }
    }

    /**
     * The buy-2 payload for the cart API (CartResource).
     */
    public function buy2Array(?CartContract $cart): array
    {
        $enabled = $this->isBuy2Enabled();
        $groupSize = $this->buy2GroupSize();
        $discounts = $this->buy2Discounts($cart);

        $units = $discounts['units'];
        $inGroup = $groupSize > 0 ? $units % $groupSize : 0;
        $needed = $units === 0 ? $groupSize : ($inGroup === 0 ? $groupSize : $groupSize - $inGroup);

        return [
            'enabled' => $enabled,
            'group_size' => $groupSize,
            'discount_percent' => $this->buy2DiscountPercent(),
            'units' => $units,
            'units_needed' => $enabled ? $needed : 0,
            'progress' => $enabled && $groupSize > 0 ? (int) min(100, round($inGroup / $groupSize * 100)) : 0,
            'discounts_earned' => $discounts['discounts_earned'],
            'discount_amount' => $discounts['total'],
            'formatted_discount_amount' => core()->formatPrice($discounts['total']),
            'applied' => $enabled && $discounts['total'] > 0,
        ];
    }

    /**
     * Is the low-stock badge enabled (admin switch)?
     */
    public function isLowStockEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.promotions.settings.low_stock_status') ?? true);
    }

    /**
     * Stock at or under which the low-stock badge shows (default 5).
     */
    public function lowStockThreshold(): int
    {
        return max(0, (int) (core()->getConfigData('general.promotions.settings.low_stock_threshold') ?? 5));
    }

    /**
     * Low-stock badge state for one product. Only simple products
     * that manage stock and are saleable can show it, only when
     * 0 < qty <= threshold, and only when Simo did not switch the
     * badge off for the product.
     */
    public function lowStockForProduct(ProductContract $product): array
    {
        $none = ['show' => false, 'qty' => 0];

        if (! $this->isLowStockEnabled()) {
            return $none;
        }

        if ($product->type !== 'simple' || ! $product->status || ! $product->manage_stock) {
            return $none;
        }

        $qty = (int) $product->getTypeInstance()->totalQuantity();

        if ($qty <= 0 || $qty > $this->lowStockThreshold()) {
            return $none;
        }

        if (! $this->showLowStockBadge((int) $product->id)) {
            return $none;
        }

        return ['show' => true, 'qty' => $qty];
    }

    /**
     * May the low-stock badge show for this product? A product
     * without a promotion row shows it; Simo hides products from
     * the admin product edit page.
     */
    public function showLowStockBadge(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->show_low_stock_badge;
    }

    /**
     * Set the per-product low-stock badge flag (admin product edit page).
     */
    public function setShowLowStockBadge(int $productId, bool $show): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['show_low_stock_badge' => $show],
        );
    }

    /**
     * May this product count towards the buy-2 groups? A product
     * without a promotion row counts; Simo excludes products from
     * the admin product edit page.
     */
    public function isEligibleBuy2(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->eligible_buy2_half;
    }

    /**
     * Set the per-product buy-2 eligibility (admin product edit page).
     */
    public function setEligibleBuy2(int $productId, bool $eligible): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['eligible_buy2_half' => $eligible],
        );
    }

    /**
     * Is this cart item a free gift?
     */
    protected function isGiftItem(CartItemContract $item): bool
    {
        return (bool) (($item->additional['is_gift'] ?? false));
    }

    /**
     * Promotion settings rows for a list of products, keyed by
     * product id, as plain arrays (JSON-safe, used in the buy-2
     * memo signature so a toggled product invalidates it).
     */
    protected function promotionSettings(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if (! $productIds) {
            return [];
        }

        $settings = ProductPromotionSetting::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id')
            ->map(fn ($row) => [
                'eligible_as_gift' => (bool) $row->eligible_as_gift,
                'show_low_stock_badge' => (bool) $row->show_low_stock_badge,
                'eligible_buy2_half' => (bool) $row->eligible_buy2_half,
            ])
            ->all();

        ksort($settings);

        return $settings;
    }

    /**
     * May this product count towards the buy-2 groups, from an
     * already-loaded settings map? A product without a row counts.
     */
    protected function isEligibleBuy2Row(array $settings, int $productId): bool
    {
        return ! isset($settings[$productId]) || $settings[$productId]['eligible_buy2_half'];
    }

    /**
     * Zero the selected flat-rate shipping price when delivery is
     * free. The rate rows are rebuilt by Shipping::collectRates()
     * whenever the customer enters the shipping step, so this only
     * fixes totals shown before that (cart page, summary).
     */
    protected function syncShippingRate(CartContract $cart): void
    {
        if (! $this->isFreeDelivery($cart)) {
            return;
        }

        $rate = $cart->selected_shipping_rate;

        if (
            ! $rate
            || $rate->method !== 'flatrate_flatrate'
            || (float) $rate->price === 0.0
        ) {
            return;
        }

        $rate->price = 0;
        $rate->base_price = 0;
        $rate->price_incl_tax = 0;
        $rate->base_price_incl_tax = 0;
        $rate->save();
    }

    /**
     * Product name in a fallback locale (the local catalogue has
     * patchy locale coverage; the live catalogue is complete).
     */
    protected function flatName(int $productId, string $locale): ?string
    {
        return DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('locale', $locale)
            ->value('name');
    }
}
