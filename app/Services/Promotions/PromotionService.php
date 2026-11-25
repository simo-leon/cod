<?php

namespace App\Services\Promotions;

use App\Models\ProductPromotionSetting;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartItem as CartItemContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartItemRepository;
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
     * Is this cart item a free gift?
     */
    protected function isGiftItem(CartItemContract $item): bool
    {
        return (bool) (($item->additional['is_gift'] ?? false));
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
