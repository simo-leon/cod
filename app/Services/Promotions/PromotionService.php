<?php

namespace App\Services\Promotions;

use App\Models\ProductPromotionSetting;
use Carbon\Carbon;
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
     * Re-entrancy guard for syncFbtDiscount (same pattern).
     */
    protected static bool $syncingFbt = false;

    /**
     * Re-entrancy guard for syncCouponDiscount (same pattern).
     */
    protected static bool $syncingCoupon = false;

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

            // Reward type: 'product' (free gift, the picker) or
            // 'coupon' (automatic fixed discount, no picker). Tier 2
            // defaults to a coupon — 49 DH over 700 DH — the other
            // tiers default to a free gift.
            $rewardType = (string) (core()->getConfigData("general.promotions.settings.tier_{$slot}_reward_type") ?? ($slot === 2 ? 'coupon' : 'product'));

            $tiers[] = [
                'tier' => $slot,
                'threshold' => $threshold,
                'max_price' => max(0, (float) (core()->getConfigData("general.promotions.settings.tier_{$slot}_max_price") ?? 0)),
                'reward_type' => in_array($rewardType, ['product', 'coupon'], true) ? $rewardType : 'product',
                'coupon_amount' => max(0, (float) (core()->getConfigData("general.promotions.settings.tier_{$slot}_coupon_amount") ?? ($slot === 2 ? 49 : 0))),
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

            // A coupon tier has no gift to pick — its discount is
            // automatic (see couponTier()).
            if (($tier['reward_type'] ?? 'product') === 'coupon') {
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

    /**
     * Fallback gift price floor (DH): when Simo has not chosen any
     * gift product, the picker shows products priced between this
     * floor and the tier cap, in random order. The cap itself is
     * the admin tier max price; the floor is clamped to it, so a
     * cap below 40 still yields gifts.
     */
    public const GIFT_FALLBACK_MIN_PRICE = 40.0;

    /**
     * Which gift list the last eligibleGifts() call returned:
     * 'explicit' (products Simo chose on the product edit page)
     * or 'range' (the 40-to-cap fallback). Read by toArray() so
     * the picker subtitle can match the list.
     */
    protected string $lastGiftSource = 'range';

    /**
     * The floor price of the last eligibleGifts() call, null for
     * the explicit list (chosen gifts have no floor, only the cap).
     */
    protected ?float $lastGiftMinPrice = null;

    /**
     * Products Simo explicitly chose as gifts: promotion rows with
     * the gift switch on and the master offers switch on. A row
     * only exists once the product's promotion card was saved, so
     * a true row is an explicit choice (see the
     * product_promotion_settings migration docblock). Products
     * without a row are NOT explicit — they are only candidates
     * for the random fallback.
     */
    public function explicitGiftProductIds(): array
    {
        return ProductPromotionSetting::query()
            ->where('eligible_as_gift', true)
            ->where('offers_enabled', true)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function eligibleGifts(array $tier, ?CartContract $cart, int $limit = 12): array
    {
        if (! $this->isEnabled() || (float) ($tier['max_price'] ?? 0) <= 0) {
            $this->lastGiftSource = 'range';
            $this->lastGiftMinPrice = null;

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

        // Excluded from every gift list: products Simo switched off
        // as gifts, and products whose master offers switch is off
        // (they would fail addGift with 'not-eligible' anyway, so
        // showing them in the picker would only be a dead end).
        $excludedIds = ProductPromotionSetting::query()
            ->where(function ($query) {
                $query->where('eligible_as_gift', false)
                    ->orWhere('offers_enabled', false);
            })
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Branch 1 — Simo chose gifts: those products, and only
        // those. No price floor here (a chosen 3 DH product is a
        // valid gift); only the tier cap applies, as addGift
        // enforces it. Ordered by final price, deterministic.
        $explicitIds = array_values(array_diff(
            $this->explicitGiftProductIds(),
            $giftedProductIds,
        ));

        // The price that counts is the FINAL (selling) price —
        // getFinalPrice(), i.e. the special price when one is
        // active — because that is what addGift validates against
        // the cap and what the customer would have paid. The flat
        // `price` column is only the base price: a product priced
        // 42 with an 85 special costs 85, and a product priced 60
        // with a 45 special costs 45. So the SQL pre-filter must
        // admit both columns, and the loop decides on the final
        // price. (Before this, the picker showed the base price
        // while addGift checked the final price, so a gift could
        // appear in the picker and then fail to add with
        // 'over-cap'.)
        if ($explicitIds) {
            $this->lastGiftSource = 'explicit';
            $this->lastGiftMinPrice = null;

            $rows = DB::table('product_flat')
                ->where('channel', $channel)
                ->where('locale', $locale)
                ->where('status', 1)
                ->where('type', 'simple')
                ->whereIn('product_id', $explicitIds)
                ->where(function ($query) use ($cap) {
                    $query->where('price', '<=', $cap)
                        ->orWhere('special_price', '<=', $cap);
                })
                ->orderBy('price')
                ->limit(40)
                ->get(['product_id', 'name', 'url_key', 'price']);
        } else {
            // Branch 2 — nobody chose anything: products whose
            // final price is between the 40 DH floor and the tier
            // cap, in random order so every page load shows a
            // different mix. The floor is clamped to the cap: when
            // the admin cap is below 40 the range is simply "up to
            // the cap".
            $floor = min(self::GIFT_FALLBACK_MIN_PRICE, $cap);
            $this->lastGiftSource = 'range';
            $this->lastGiftMinPrice = $floor;

            $rows = DB::table('product_flat')
                ->where('channel', $channel)
                ->where('locale', $locale)
                ->where('status', 1)
                ->where('type', 'simple')
                ->where(function ($query) use ($floor, $cap) {
                    $query->where(function ($query) use ($floor, $cap) {
                        $query->where('price', '>=', $floor)
                            ->where('price', '<=', $cap);
                    })->orWhere(function ($query) use ($floor, $cap) {
                        $query->where('special_price', '>=', $floor)
                            ->where('special_price', '<=', $cap);
                    });
                })
                ->inRandomOrder()
                ->limit(60)
                ->get(['product_id', 'name', 'url_key', 'price']);
        }

        $gifts = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            // A gift without a URL cannot be shown or opened: a
            // product imported in one locale only (doshift products
            // are fr-only) has url_key NULL in the other locales'
            // flat rows, and route() throws UrlGenerationException
            // on a null slug — which crashed the whole cart (500)
            // as soon as the gift tier unlocked (subtotal >= 400)
            // in Arabic. The FBT and daily-deal lists already skip
            // these rows the same way; eligibleGifts was the only
            // one missing the guard.
            if (
                in_array($productId, $giftedProductIds, true)
                || in_array($productId, $excludedIds, true)
                || empty($row->url_key)
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

            // Final selling price decides: the fallback floor and
            // the tier cap both apply to it, and it is the price
            // shown struck-through in the picker.
            $finalPrice = (float) $type->getFinalPrice();

            if ($finalPrice <= 0 || $finalPrice > $cap) {
                continue;
            }

            if ($this->lastGiftSource === 'range' && $finalPrice < $this->lastGiftMinPrice) {
                continue;
            }

            $name = $row->name ?: $this->flatName($productId, 'en');
            $image = ProductImage::getProductBaseImage($product);

            $gifts[] = [
                'id' => $productId,
                'name' => $name ?: $product->name,
                'price' => $finalPrice,
                'formatted_price' => core()->formatPrice($finalPrice),
                'image' => $image['medium_image_url'] ?? ($image['original_image_url'] ?? null),
                'url' => route('shop.product_or_category.index', $row->url_key),
            ];

            if (count($gifts) >= $limit) {
                break;
            }
        }

        return $gifts;
    }

    /**
     * May this product be offered as a free gift? A product without
     * a promotion row may; Simo excludes products from the admin
     * product edit page. The master toggle gates it too.
     */
    public function isEligibleAsGift(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || ((bool) $row->offers_enabled && (bool) $row->eligible_as_gift);
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

        if (($tierData['reward_type'] ?? 'product') === 'coupon') {
            throw new \Exception(trans('shop::promotions.coupon-tier'));
        }

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
            $nextTier['formatted_coupon_amount'] = core()->formatPrice($nextTier['coupon_amount'] ?? 0);
            $nextTier['progress'] = $nextTier['threshold'] > 0
                ? (int) min(100, round($subtotal / $nextTier['threshold'] * 100))
                : 100;
        }

        $eligibleGifts = $currentTier ? $this->eligibleGifts($currentTier, $cart) : [];

        if ($currentTier) {
            $currentTier['formatted_threshold'] = core()->formatPrice($currentTier['threshold']);
            $currentTier['formatted_max_price'] = core()->formatPrice($currentTier['max_price']);
            $currentTier['formatted_coupon_amount'] = core()->formatPrice($currentTier['coupon_amount'] ?? 0);

            // Which list the picker shows, so its subtitle matches:
            // 'explicit' = products Simo chose (subtitle "under the
            // cap"), 'range' = the random 40-to-cap fallback
            // (subtitle "from :min to :max").
            $currentTier['gift_source'] = $this->lastGiftSource;
            $currentTier['formatted_min_price'] = $this->lastGiftMinPrice !== null
                ? core()->formatPrice($this->lastGiftMinPrice)
                : null;
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
            'coupon' => $this->couponArray($cart),
            'gifted_tiers' => $giftedTiers,
            'eligible_gifts' => $eligibleGifts,
        ];
    }

    /**
     * The unlocked coupon tier, if any: the coupon tier with the
     * highest threshold the cart subtotal reached, or null. When
     * several coupon tiers are unlocked only the best one pays —
     * the discounts do not stack.
     */
    public function couponTier(?CartContract $cart): ?array
    {
        if (! $this->isEnabled() || ! $cart) {
            return null;
        }

        $subtotal = $this->cartSubtotal($cart);
        $unlocked = null;

        foreach ($this->giftTiers() as $tier) {
            if (($tier['reward_type'] ?? 'product') !== 'coupon') {
                continue;
            }

            if ($subtotal < $tier['threshold'] || (float) ($tier['coupon_amount'] ?? 0) <= 0) {
                continue;
            }

            $unlocked = $tier;
        }

        return $unlocked;
    }

    /**
     * The automatic coupon discount for this cart (0 when no coupon
     * tier is unlocked), capped at the subtotal so the discount can
     * never exceed what the customer pays.
     */
    public function couponDiscount(?CartContract $cart): float
    {
        $tier = $this->couponTier($cart);

        if (! $tier) {
            return 0.0;
        }

        return round(min((float) $tier['coupon_amount'], $this->cartSubtotal($cart)), 2);
    }

    /**
     * The coupon discount for the cart: per-item amounts plus
     * totals. The amount is spread over the non-gift items in cart
     * order, each item taking at most its remaining headroom
     * (item total minus the discounts already on it), so no line
     * ever goes below 0. Gift items never get it.
     *
     * Returns ['items' => [itemId => ['discount' => x,
     * 'base_discount' => y]], 'total' => x, 'base_total' => y].
     */
    public function couponDiscounts(?CartContract $cart): array
    {
        $empty = ['items' => [], 'total' => 0.0, 'base_total' => 0.0];

        if (! $this->isEnabled() || ! $cart) {
            return $empty;
        }

        $amount = $this->couponDiscount($cart);

        if ($amount <= 0) {
            return $empty;
        }

        $items = [];
        $total = 0.0;
        $baseTotal = 0.0;
        $remaining = $amount;
        $baseRemaining = $amount;

        foreach ($cart->items as $item) {
            if ($remaining <= 0) {
                break;
            }

            if ($this->isGiftItem($item)) {
                continue;
            }

            $headroom = round(
                (float) $item->price * (int) $item->quantity - (float) $item->discount_amount,
                2
            );

            if ($headroom <= 0) {
                continue;
            }

            $discount = round(min($headroom, $remaining), 2);
            $baseHeadroom = round(
                (float) $item->base_price * (int) $item->quantity - (float) $item->base_discount_amount,
                2
            );
            $baseDiscount = round(min(max(0, $baseHeadroom), $baseRemaining), 2);

            $items[(int) $item->id] = [
                'discount' => $discount,
                'base_discount' => $baseDiscount,
            ];

            $total = round($total + $discount, 2);
            $baseTotal = round($baseTotal + $baseDiscount, 2);
            $remaining = round($remaining - $discount, 2);
            $baseRemaining = round($baseRemaining - $baseDiscount, 2);
        }

        return [
            'items' => $items,
            'total' => $total,
            'base_total' => $baseTotal,
        ];
    }

    /**
     * The coupon discount amount (in cart currency) of one cart
     * item, 0 when the item has none.
     */
    public function couponDiscountForItem(int $itemId): float
    {
        $item = $this->cartItemRepository->find($itemId);

        if (! $item || ! $item->cart) {
            return 0.0;
        }

        $discounts = $this->couponDiscounts($item->cart);

        return (float) ($discounts['items'][$itemId]['discount'] ?? 0.0);
    }

    /**
     * Enforce the coupon discount on the cart. Runs on
     * checkout.cart.calculate.items.tax.before — after the FBT
     * listener (registered before it) and before the tax calc and
     * the totals summation — so the discount flows into tax, the
     * cart totals and the order. Adds the computed discount on top
     * of any cart rule, buy-2 and FBT discount, capped at the item
     * total. Never dispatches cart events.
     */
    public function syncCouponDiscount(?CartContract $cart): void
    {
        if (! $cart || static::$syncingCoupon) {
            return;
        }

        static::$syncingCoupon = true;

        try {
            // Same staleness guard as syncCart: reload the items
            // from the database before computing.
            $cart->load('items');

            $discounts = $this->couponDiscounts($cart);

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
            static::$syncingCoupon = false;
        }
    }

    /**
     * The coupon payload for the cart API (CartResource).
     */
    public function couponArray(?CartContract $cart): array
    {
        $enabled = $this->isEnabled();
        $tier = $this->couponTier($cart);
        $amount = $tier ? $this->couponDiscount($cart) : 0.0;

        return [
            'enabled' => $enabled,
            'tier' => $tier ? (int) $tier['tier'] : null,
            'threshold' => $tier ? (float) $tier['threshold'] : null,
            'formatted_threshold' => $tier ? core()->formatPrice($tier['threshold']) : null,
            'amount' => $amount,
            'formatted_amount' => core()->formatPrice($amount),
            'applied' => $enabled && $amount > 0,
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
     * Price ceiling of the buy-2 offer in DH (default 150): only
     * products whose final (selling) price is at or under the
     * ceiling join the offer. 0 means "no ceiling".
     */
    public function buy2MaxPrice(): float
    {
        return max(0, (float) (core()->getConfigData('general.promotions.settings.buy2_max_price') ?? 150));
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

        // The ceiling is part of the memo key: changing it in the
        // admin must not serve a discount computed under the old one.
        $ceiling = $this->buy2MaxPrice();

        $key = (int) $cart->id.':'.md5(implode('|', $parts).'|'.json_encode($settings).'|ceiling:'.$ceiling);

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

            // Price ceiling: a unit priced above the ceiling does not
            // join any buy-2 group (and gets no discount).
            if ($ceiling > 0 && (float) $item->price > $ceiling) {
                continue;
            }

            // Each unit carries its own product's terms (group size
            // and discount rate): per-product values set by Simo
            // override the globals, null means "use the global".
            $terms = $this->buy2TermsRow($settings, (int) $item->product_id);

            for ($i = 0; $i < (int) $item->quantity; $i++) {
                $units[] = [
                    'item_id' => (int) $item->id,
                    'price' => (float) $item->price,
                    'base_price' => (float) $item->base_price,
                    'group_size' => $terms['group_size'],
                    'rate' => $terms['rate'],
                ];
            }
        }

        $items = [];
        $total = 0.0;
        $baseTotal = 0.0;
        $discountsEarned = 0;

        if ($units) {
            usort($units, fn ($a, $b) => $a['price'] <=> $b['price'] ?: $a['item_id'] <=> $b['item_id']);

            // Walk the pool cheapest first. Each group starts at its
            // cheapest unit: that unit's product sets the group size
            // (buy N + 1) and the discount percent. With the global
            // defaults everywhere this is exactly the old rule —
            // every 3rd cheapest unit at 50% off. A unit whose
            // product has a 0% rate cannot start a group; it stays
            // in the pool as filler for a later group.
            $i = 0;
            $count = count($units);

            while ($i < $count) {
                $unit = $units[$i];
                $groupSize = max(2, (int) $unit['group_size']);
                $rate = (float) $unit['rate'];

                if ($rate <= 0) {
                    $i++;

                    continue;
                }

                if ($i + $groupSize > $count) {
                    break;
                }

                $discount = round($unit['price'] * $rate, 2);
                $baseDiscount = round($unit['base_price'] * $rate, 2);
                $itemId = $unit['item_id'];

                $items[$itemId] = [
                    'discount' => round(($items[$itemId]['discount'] ?? 0) + $discount, 2),
                    'base_discount' => round(($items[$itemId]['base_discount'] ?? 0) + $baseDiscount, 2),
                ];

                $total = round($total + $discount, 2);
                $baseTotal = round($baseTotal + $baseDiscount, 2);
                $discountsEarned++;

                $i += $groupSize;
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
     * the admin product edit page. The master toggle gates it too.
     */
    public function showLowStockBadge(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || ((bool) $row->offers_enabled && (bool) $row->show_low_stock_badge);
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
     * the admin product edit page. The master toggle gates it too:
     * a product with its offers switched off counts for nothing.
     */
    public function isEligibleBuy2(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || ((bool) $row->offers_enabled && (bool) $row->eligible_buy2_half);
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
     * Is the master offers toggle on for this product? A product
     * without a row has its offers on. Off means the product takes
     * part in no offer at all — free gift, low-stock badge, buy-2,
     * FBT, daily deals — while its individual toggles keep their
     * values, so switching it back on restores them as they were.
     * (Reviews, order tracking and abandoned-cart reminders are
     * not offers and are NOT gated by this switch.)
     */
    public function offersEnabled(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->offers_enabled;
    }

    /**
     * Set the master offers toggle (admin product edit page).
     */
    public function setOffersEnabled(int $productId, bool $enabled): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['offers_enabled' => $enabled],
        );
    }

    /**
     * The effective buy-2 terms of one product: how many units the
     * customer must buy ("اشتري N") and the discount percent of the
     * extra unit. A per-product value (admin product edit page)
     * overrides the global setting; null means "use the global".
     * The group size is min_qty + 1 (buy 2 -> group of 3).
     * The badge is disabled when the product's final (selling)
     * price is above the buy-2 price ceiling.
     */
    public function buy2TermsForProduct(ProductContract $product): array
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', (int) $product->id)
            ->first();

        $minQty = $row && $row->buy2_min_qty !== null
            ? max(1, (int) $row->buy2_min_qty)
            : max(1, $this->buy2GroupSize() - 1);

        $percent = $row && $row->buy2_discount_percent !== null
            ? min(100, max(0, (float) $row->buy2_discount_percent))
            : $this->buy2DiscountPercent();

        $ceiling = $this->buy2MaxPrice();

        $price = (float) $product->getTypeInstance()->getFinalPrice();

        return [
            'enabled' => $this->isBuy2Enabled()
                && $this->isEligibleBuy2((int) $product->id)
                && ($ceiling <= 0 || $price <= $ceiling),
            'min_qty' => $minQty,
            'discount_percent' => $percent,
            'discount_percent_display' => $percent == (int) $percent ? (int) $percent : $percent,
            'group_size' => $minQty + 1,
            'max_price' => $ceiling,
            'custom' => $row !== null && ($row->buy2_min_qty !== null || $row->buy2_discount_percent !== null),
        ];
    }

    /**
     * Set the per-product buy-2 terms (admin product edit page).
     * Null for either value means "use the global setting".
     */
    public function setBuy2Terms(int $productId, ?int $minQty, ?float $discountPercent): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            [
                'buy2_min_qty' => $minQty !== null ? max(1, $minQty) : null,
                'buy2_discount_percent' => $discountPercent !== null
                    ? (int) min(100, max(0, $discountPercent))
                    : null,
            ],
        );
    }

    /**
     * The raw per-product buy-2 terms as Simo set them (null =
     * "use the global setting"), for the admin product edit form.
     * Returns ['min_qty' => ?int, 'discount_percent' => ?float].
     */
    public function buy2CustomTerms(int $productId): array
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return [
            'min_qty' => $row && $row->buy2_min_qty !== null ? (int) $row->buy2_min_qty : null,
            'discount_percent' => $row && $row->buy2_discount_percent !== null ? (float) $row->buy2_discount_percent : null,
        ];
    }

    /**
     * The raw per-product offer toggles as stored in the database,
     * WITHOUT the master toggle gating — for the admin product
     * edit form. The effective (gated) methods must never feed the
     * form: when the master toggle is off they return false, so
     * the form would render the toggles unchecked and the next
     * save would wipe the values the master toggle is meant to
     * preserve. A product without a row participates in
     * everything (except the daily deals, which are opt-in).
     * Returns the same keys as promotionSettings() for one product.
     */
    public function promotionRowValues(int $productId): array
    {
        $settings = $this->promotionSettings([$productId]);

        return $settings[$productId] ?? [
            'eligible_as_gift' => true,
            'show_low_stock_badge' => true,
            'eligible_buy2_half' => true,
            'show_fbt' => true,
            'fbt_complementary' => true,
            'in_daily_deal' => false,
            'offers_enabled' => true,
            'buy2_min_qty' => null,
            'buy2_discount_percent' => null,
        ];
    }

    /**
     * Is the "Frequently bought together" section enabled (admin switch)?
     */
    public function isFbtEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.promotions.settings.fbt_status') ?? true);
    }

    /**
     * Bundle discount percent applied to the complementary products
     * bought together with the main product (0-100, default 10).
     */
    public function fbtDiscountPercent(): float
    {
        return min(100, max(0, (float) (core()->getConfigData('general.promotions.settings.fbt_discount_percent') ?? 10)));
    }

    /**
     * How many complementary products the FBT section shows at most
     * (default 4).
     */
    public function fbtLimit(): int
    {
        return max(1, (int) (core()->getConfigData('general.promotions.settings.fbt_limit') ?? 4));
    }

    /**
     * Complementary products for one product page: other active,
     * simple, saleable products from the same categories (excluding
     * the product itself), cheapest first, never products Simo
     * excluded on the product edit page, never out-of-stock ones.
     */
    public function fbtProducts(ProductContract $product, ?int $limit = null): array
    {
        if (! $this->isFbtEnabled() || $product->type !== 'simple' || ! $product->status) {
            return [];
        }

        if (! $this->showFbt((int) $product->id)) {
            return [];
        }

        $categoryIds = $product->categories
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id !== 1)
            ->values()
            ->all();

        if (! $categoryIds) {
            return [];
        }

        $locale = core()->getCurrentLocale()->code;
        $channel = core()->getCurrentChannelCode();

        $excludedIds = ProductPromotionSetting::query()
            ->where('fbt_complementary', false)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = DB::table('product_flat')
            ->join('product_categories', 'product_flat.product_id', '=', 'product_categories.product_id')
            ->where('product_flat.channel', $channel)
            ->where('product_flat.locale', $locale)
            ->where('product_flat.status', 1)
            ->where('product_flat.type', 'simple')
            ->where('product_flat.product_id', '!=', (int) $product->id)
            ->whereIn('product_categories.category_id', $categoryIds)
            ->distinct()
            ->orderBy('product_flat.price')
            ->limit(40)
            ->get(['product_flat.product_id', 'product_flat.name', 'product_flat.url_key', 'product_flat.price']);

        $complements = [];
        $max = $limit ?? $this->fbtLimit();

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            if (
                in_array($productId, $excludedIds, true)
                || empty($row->url_key)
            ) {
                continue;
            }

            $candidate = $this->productRepository->find($productId);

            if (! $candidate) {
                continue;
            }

            $type = $candidate->getTypeInstance();

            if (! $type->isSaleable() || ! $type->haveSufficientQuantity(1)) {
                continue;
            }

            $price = (float) $type->getFinalPrice();

            if ($price <= 0) {
                continue;
            }

            $name = $row->name ?: $this->flatName($productId, 'en');
            $image = ProductImage::getProductBaseImage($candidate);

            $complements[] = [
                'id' => $productId,
                'name' => $name ?: $candidate->name,
                'price' => $price,
                'formatted_price' => core()->formatPrice($price),
                'image' => $image['medium_image_url'] ?? ($image['original_image_url'] ?? null),
                'url' => route('shop.product_or_category.index', $row->url_key),
            ];

            if (count($complements) >= $max) {
                break;
            }
        }

        return $complements;
    }

    /**
     * May the FBT section show on this product's page? A product
     * without a promotion row shows it; Simo hides it from the
     * admin product edit page. The master toggle gates it too.
     */
    public function showFbt(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || ((bool) $row->offers_enabled && (bool) $row->show_fbt);
    }

    /**
     * Set the per-product FBT section flag (admin product edit page).
     */
    public function setShowFbt(int $productId, bool $show): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['show_fbt' => $show],
        );
    }

    /**
     * May this product be suggested as a complementary product in
     * the FBT section? A product without a promotion row may; Simo
     * excludes products from the admin product edit page. The
     * master toggle gates it too.
     */
    public function isFbtComplementary(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || ((bool) $row->offers_enabled && (bool) $row->fbt_complementary);
    }

    /**
     * Set the per-product FBT complementary flag (admin product edit page).
     */
    public function setFbtComplementary(int $productId, bool $eligible): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['fbt_complementary' => $eligible],
        );
    }

    /**
     * Add the main product (when not already in the cart) and the
     * chosen complementary products to the cart in one action. The
     * complements are marked with the bundle (main product id +
     * discount percent) so the discount applies while the main
     * product stays in the cart.
     */
    public function addFbtBundle(CartContract $cart, int $mainProductId, array $complementIds): array
    {
        if (! $this->isFbtEnabled()) {
            throw new \Exception(trans('shop::promotions.disabled'));
        }

        $main = $this->productRepository->find($mainProductId);

        if (! $main || $main->type !== 'simple' || ! $main->status) {
            throw new \Exception(trans('shop::promotions.invalid-product'));
        }

        if (! $this->showFbt($mainProductId)) {
            throw new \Exception(trans('shop::promotions.not-eligible'));
        }

        $rate = $this->fbtDiscountPercent();

        $complementIds = array_slice(
            array_values(array_unique(array_map('intval', $complementIds))),
            0,
            $this->fbtLimit()
        );

        if (! $complementIds) {
            throw new \Exception(trans('shop::promotions.fbt-empty'));
        }

        $added = [];
        $skipped = [];

        // Main product first, so the bundle discount can apply
        // right away to the complements added below.
        //
        // NOTE: never read $cart->items here. Touching the relation
        // on this shared cart model loads it (empty, for a fresh
        // cart) and it stays stale: Cart::addProduct creates the
        // item through the repository, then validateItems() sees the
        // stale empty relation, deletes the cart, and addProduct()
        // returns null (TypeError). Query the items through the
        // repository instead, and unset the relations before every
        // addProduct call.
        $mainInCart = $this->cartItemRepository->findWhere([
            'cart_id' => $cart->id,
            'product_id' => $mainProductId,
        ])->contains(fn ($item) => ! $this->isGiftItem($item));

        if (! $mainInCart) {
            try {
                $cart->unsetRelation('items');
                $cart->unsetRelation('all_items');

                Cart::addProduct($main, ['product_id' => $main->id, 'quantity' => 1]);
                $cart = Cart::getCart();
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
        }

        foreach ($complementIds as $complementId) {
            if ($complementId === $mainProductId) {
                $skipped[] = ['id' => $complementId, 'reason' => trans('shop::promotions.fbt-same-product')];

                continue;
            }

            $complement = $this->productRepository->find($complementId);

            if (! $complement || $complement->type !== 'simple' || ! $complement->status) {
                $skipped[] = ['id' => $complementId, 'reason' => trans('shop::promotions.invalid-product')];

                continue;
            }

            if (! $this->isFbtComplementary($complementId)) {
                $skipped[] = ['id' => $complementId, 'reason' => trans('shop::promotions.not-eligible')];

                continue;
            }

            $type = $complement->getTypeInstance();

            if (! $type->isSaleable() || ! $type->haveSufficientQuantity(1)) {
                $skipped[] = ['id' => $complementId, 'reason' => trans('shop::promotions.out-of-stock')];

                continue;
            }

            if ((float) $type->getFinalPrice() <= 0) {
                $skipped[] = ['id' => $complementId, 'reason' => trans('shop::promotions.invalid-product')];

                continue;
            }

            try {
                $cart->unsetRelation('items');
                $cart->unsetRelation('all_items');

                Cart::addProduct($complement, ['product_id' => $complement->id, 'quantity' => 1]);
            } catch (\Exception $e) {
                $skipped[] = ['id' => $complementId, 'reason' => $e->getMessage()];

                continue;
            }

            // Mark the line as part of this bundle, so the discount
            // applies while the main product stays in the cart. The
            // marker is merged into the item's additional data — the
            // item itself stays a normal priced item. (Repository
            // query again — see the note above about the stale
            // relation on the shared cart model.)
            $cart = Cart::getCart();

            $item = $this->cartItemRepository->findWhere([
                'cart_id' => $cart->id,
                'product_id' => $complementId,
            ])->first(fn ($i) => ! $this->isGiftItem($i));

            if ($item) {
                $additional = $item->additional ?? [];
                $additional['fbt_main_product_id'] = $mainProductId;
                $additional['fbt_discount_percent'] = $rate;

                $this->cartItemRepository->update(['additional' => $additional], (int) $item->id);
            }

            $added[] = $complementId;
        }

        Cart::collectTotals();

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * The FBT discount for the cart: per-item amounts plus totals.
     * Each marked complement gets fbtDiscountPercent() off ONE unit
     * — the unit bought together with the main product — but only
     * while the main product of its bundle is in the cart (not as
     * a gift). Gift items never get it.
     */
    public function fbtDiscounts(?CartContract $cart): array
    {
        $empty = ['items' => [], 'total' => 0.0, 'base_total' => 0.0];

        if (! $this->isFbtEnabled() || ! $cart) {
            return $empty;
        }

        $mainIds = collect($cart->items)
            ->filter(fn ($item) => ! $this->isGiftItem($item))
            ->map(fn ($item) => (int) $item->product_id)
            ->unique()
            ->all();

        $items = [];
        $total = 0.0;
        $baseTotal = 0.0;

        foreach ($cart->items as $item) {
            if ($this->isGiftItem($item)) {
                continue;
            }

            $mainId = (int) ($item->additional['fbt_main_product_id'] ?? 0);

            if ($mainId <= 0 || ! in_array($mainId, $mainIds, true)) {
                continue;
            }

            $rate = (float) ($item->additional['fbt_discount_percent'] ?? $this->fbtDiscountPercent());

            if ($rate <= 0) {
                continue;
            }

            $discount = round(min((float) $item->price, (float) $item->price * $rate / 100), 2);
            $baseDiscount = round(min((float) $item->base_price, (float) $item->base_price * $rate / 100), 2);

            if ($discount <= 0) {
                continue;
            }

            $items[(int) $item->id] = [
                'discount' => $discount,
                'base_discount' => $baseDiscount,
            ];

            $total = round($total + $discount, 2);
            $baseTotal = round($baseTotal + $baseDiscount, 2);
        }

        return [
            'items' => $items,
            'total' => $total,
            'base_total' => $baseTotal,
        ];
    }

    /**
     * The FBT discount amount (in cart currency) of one cart item,
     * 0 when the item has none.
     */
    public function fbtDiscountForItem(int $itemId): float
    {
        $item = $this->cartItemRepository->find($itemId);

        if (! $item || ! $item->cart) {
            return 0.0;
        }

        $discounts = $this->fbtDiscounts($item->cart);

        return (float) ($discounts['items'][$itemId]['discount'] ?? 0.0);
    }

    /**
     * Enforce the FBT discount on the cart. Runs on
     * checkout.cart.calculate.items.tax.before — after the buy-2
     * listener (registered before it) and before the tax calc and
     * the totals summation — so the discount flows into tax, the
     * cart totals and the order. Adds the computed discount on top
     * of any cart rule discount and the buy-2 discount, capped at
     * the item total. Never dispatches cart events.
     */
    public function syncFbtDiscount(?CartContract $cart): void
    {
        if (! $cart || static::$syncingFbt) {
            return;
        }

        static::$syncingFbt = true;

        try {
            // Same staleness guard as syncCart: reload the items
            // from the database before computing.
            $cart->load('items');

            $discounts = $this->fbtDiscounts($cart);

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
            static::$syncingFbt = false;
        }
    }

    /**
     * The FBT payload for the cart API (CartResource).
     */
    public function fbtArray(?CartContract $cart): array
    {
        $enabled = $this->isFbtEnabled();
        $discounts = $this->fbtDiscounts($cart);

        return [
            'enabled' => $enabled,
            'discount_percent' => $this->fbtDiscountPercent(),
            'discount_amount' => $discounts['total'],
            'formatted_discount_amount' => core()->formatPrice($discounts['total']),
            'applied' => $enabled && $discounts['total'] > 0,
        ];
    }

    /**
     * Is the daily-deals / flash-sale section enabled (admin switch)?
     */
    public function isDealsEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.promotions.settings.deals_status') ?? true);
    }

    /**
     * Custom title for the daily-deals section (admin text field),
     * null when Simo left it empty (the translated default title
     * is used then).
     */
    public function dealsTitle(): ?string
    {
        $title = trim((string) (core()->getConfigData('general.promotions.settings.deals_title') ?? ''));

        return $title === '' ? null : $title;
    }

    /**
     * How many deal products the section shows at most (default 8).
     */
    public function dealsLimit(): int
    {
        return max(1, (int) (core()->getConfigData('general.promotions.settings.deals_limit') ?? 8));
    }

    /**
     * When the current daily deals end: midnight tonight plus the
     * configured duration, Africa/Casablanca time. The duration
     * (Configuration → General → Homepage) counts from midnight:
     * 24 hours ends at midnight tonight, 0 always ends at midnight.
     * The countdown ticks down to this moment; tomorrow it is a new
     * day with a new countdown. A deals-countdown topic may pass its
     * own duration in hours (null = the global setting).
     */
    public function dealsEndsAt(?int $hours = null): Carbon
    {
        $hours ??= (int) (core()->getConfigData('general.homepage.settings.deals_duration_hours') ?? 24);

        return $hours > 0
            ? Carbon::today('Africa/Casablanca')->addHours($hours)
            : Carbon::now('Africa/Casablanca')->endOfDay();
    }

    /**
     * Deal products for the homepage: products Simo explicitly
     * marked for the daily deals (in_daily_deal) whose special
     * price — the deal price — is below their regular price,
     * active, simple and saleable. Biggest discount first.
     */
    public function dealProducts(?int $limit = null): array
    {
        if (! $this->isDealsEnabled()) {
            return [];
        }

        $locale = core()->getCurrentLocale()->code;
        $channel = core()->getCurrentChannelCode();

        $markedIds = ProductPromotionSetting::query()
            ->where('in_daily_deal', true)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $markedIds) {
            return [];
        }

        $rows = DB::table('product_flat')
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->where('status', 1)
            ->where('type', 'simple')
            ->whereIn('product_id', $markedIds)
            ->where('price', '>', 0)
            ->get(['product_id', 'name', 'url_key', 'price']);

        $deals = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            if (empty($row->url_key)) {
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

            $price = (float) $row->price;
            $dealPrice = (float) $type->getFinalPrice();

            if ($dealPrice <= 0 || $dealPrice >= $price) {
                continue;
            }

            $name = $row->name ?: $this->flatName($productId, 'en');
            $image = ProductImage::getProductBaseImage($product);

            $deals[] = [
                'id' => $productId,
                'name' => $name ?: $product->name,
                'price' => $price,
                'formatted_price' => core()->formatPrice($price),
                'deal_price' => $dealPrice,
                'formatted_deal_price' => core()->formatPrice($dealPrice),
                'discount_percent' => (int) round((1 - $dealPrice / $price) * 100),
                'image' => $image['medium_image_url'] ?? ($image['original_image_url'] ?? null),
                'url' => route('shop.product_or_category.index', $row->url_key),
            ];
        }

        usort($deals, fn ($a, $b) => $b['discount_percent'] <=> $a['discount_percent'] ?: $a['deal_price'] <=> $b['deal_price']);

        return array_slice($deals, 0, $limit ?? $this->dealsLimit());
    }

    /**
     * Is this product marked for the daily deals? Opt-in: only a
     * product Simo explicitly marked appears in the section. The
     * master toggle gates it too.
     */
    public function isInDailyDeal(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row !== null && (bool) $row->offers_enabled && (bool) $row->in_daily_deal;
    }

    /**
     * Set the per-product daily-deal flag (admin product edit page).
     */
    public function setInDailyDeal(int $productId, bool $inDeal): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['in_daily_deal' => $inDeal],
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
                'show_fbt' => (bool) $row->show_fbt,
                'fbt_complementary' => (bool) $row->fbt_complementary,
                'in_daily_deal' => (bool) $row->in_daily_deal,
                'offers_enabled' => (bool) $row->offers_enabled,
                'buy2_min_qty' => $row->buy2_min_qty !== null ? (int) $row->buy2_min_qty : null,
                'buy2_discount_percent' => $row->buy2_discount_percent !== null ? (int) $row->buy2_discount_percent : null,
            ])
            ->all();

        ksort($settings);

        return $settings;
    }

    /**
     * May this product count towards the buy-2 groups, from an
     * already-loaded settings map? A product without a row counts.
     * The master toggle gates it too.
     */
    protected function isEligibleBuy2Row(array $settings, int $productId): bool
    {
        return ! isset($settings[$productId])
            || ($settings[$productId]['offers_enabled'] && $settings[$productId]['eligible_buy2_half']);
    }

    /**
     * The effective buy-2 terms of one product, from an
     * already-loaded settings map: group size (buy N + 1) and
     * discount rate. Per-product values override the globals;
     * null means "use the global".
     */
    protected function buy2TermsRow(array $settings, int $productId): array
    {
        $row = $settings[$productId] ?? null;

        $minQty = $row && $row['buy2_min_qty'] !== null
            ? max(1, (int) $row['buy2_min_qty'])
            : max(1, $this->buy2GroupSize() - 1);

        $percent = $row && $row['buy2_discount_percent'] !== null
            ? min(100, max(0, (float) $row['buy2_discount_percent']))
            : $this->buy2DiscountPercent();

        return [
            'min_qty' => $minQty,
            'group_size' => $minQty + 1,
            'discount_percent' => $percent,
            'rate' => $percent / 100,
        ];
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
