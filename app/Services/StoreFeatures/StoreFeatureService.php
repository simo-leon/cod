<?php

namespace App\Services\StoreFeatures;

use App\Cod\Support\PhoneNumber;
use App\Models\OrderStatusHistory;
use App\Models\ProductPromotionSetting;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Contracts\Product as ProductContract;
use Webkul\Product\Facades\ProductImage;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * Store features (ideas 5-6): product reviews (Bagisto core system,
 * wired up with Jemla admin switches, per-product toggles and
 * verified-purchase marking) and public order tracking by phone
 * number. Every setting comes from the admin
 * (general.store-features.*); nothing is hard-coded.
 *
 * Privacy: tracking only ever returns orders whose billing/shipping
 * phone (or COD snapshot phone) matches the exact normalized number
 * the visitor typed. A phone number is not an order number — it
 * cannot be used to enumerate other customers' orders.
 */
class StoreFeatureService implements StoreFeatureServiceInterface
{
    public function __construct(
        protected OrderRepository $orderRepository,
        protected ProductRepository $productRepository,
    ) {}

    public function isReviewsEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.store-features.reviews.status') ?? true);
    }

    public function reviewsRequireApproval(): bool
    {
        return (bool) (core()->getConfigData('general.store-features.reviews.require_approval') ?? true);
    }

    public function reviewsAllowGuests(): bool
    {
        return (bool) (core()->getConfigData('general.store-features.reviews.guest_reviews') ?? true);
    }

    public function reviewsVisible(ProductContract $product): bool
    {
        return $this->isReviewsEnabled() && $this->allowReviews((int) $product->id);
    }

    public function allowReviews(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->allow_reviews;
    }

    public function setAllowReviews(int $productId, bool $allow): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['allow_reviews' => $allow],
        );
    }

    public function isTrackingEnabled(): bool
    {
        return (bool) (core()->getConfigData('general.store-features.tracking.status') ?? true);
    }

    public function showInTracking(int $productId): bool
    {
        $row = ProductPromotionSetting::query()
            ->where('product_id', $productId)
            ->first();

        return $row === null || (bool) $row->show_in_tracking;
    }

    public function setShowInTracking(int $productId, bool $show): void
    {
        ProductPromotionSetting::updateOrCreate(
            ['product_id' => $productId],
            ['show_in_tracking' => $show],
        );
    }

    /**
     * A purchase is verified when a non-cancelled order containing
     * this product matches the reviewer's phone (billing/shipping
     * address or COD snapshot), email, or customer account.
     */
    public function isVerifiedPurchase(int $productId, ?string $phone, ?string $email, ?int $customerId): bool
    {
        $normalized = $phone ? PhoneNumber::normalize($phone) : null;
        $email = $email ? mb_strtolower(trim($email)) : null;

        if (! $normalized && ! $email && ! $customerId) {
            return false;
        }

        // Email / account match, grouped so the ORs cannot escape
        // the product + not-cancelled conditions.
        $orderIds = [];

        if ($email || $customerId) {
            $orderIds = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $productId)
                ->whereNotIn('orders.status', ['canceled', 'fraud'])
                ->where(function ($q) use ($email, $customerId) {
                    if ($email) {
                        $q->orWhere('orders.customer_email', $email);
                    }

                    if ($customerId) {
                        $q->orWhere('orders.customer_id', $customerId);
                    }
                })
                ->distinct()
                ->pluck('orders.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // Phone match: the email/customer filters above cannot see a
        // phone, so check the addresses + COD snapshot of every order
        // for this product. The per-product order count is small, and
        // this only runs once per submitted review.
        if ($normalized) {
            $phoneOrderIds = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $productId)
                ->whereNotIn('orders.status', ['canceled', 'fraud'])
                ->distinct()
                ->pluck('orders.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($phoneOrderIds) {
                $tail = substr($normalized, -9);

                $phones = DB::table('addresses')
                    ->whereIn('order_id', $phoneOrderIds)
                    ->where('phone', 'like', '%'.$tail.'%')
                    ->get(['order_id', 'phone']);

                foreach ($phones as $row) {
                    if (PhoneNumber::normalize((string) $row->phone) === $normalized) {
                        $orderIds[] = (int) $row->order_id;
                    }
                }

                $codPhones = DB::table('cod_orders')
                    ->whereIn('order_id', $phoneOrderIds)
                    ->get(['order_id', 'customer_phone_normalized']);

                foreach ($codPhones as $row) {
                    if ((string) $row->customer_phone_normalized === $normalized) {
                        $orderIds[] = (int) $row->order_id;
                    }
                }
            }
        }

        return ! empty(array_unique($orderIds));
    }

    public function findOrdersByPhone(string $phone): array
    {
        $normalized = PhoneNumber::normalize($phone);

        if (strlen($normalized) < 9) {
            return [];
        }

        $tail = substr($normalized, -9);

        $orderIds = DB::table('addresses')
            ->where('phone', 'like', '%'.$tail.'%')
            ->whereNotNull('order_id')
            ->get(['order_id', 'phone'])
            ->filter(fn ($row) => PhoneNumber::normalize((string) $row->phone) === $normalized)
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $codIds = DB::table('cod_orders')
            ->where('customer_phone_normalized', $normalized)
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orderIds = array_values(array_unique(array_merge($orderIds, $codIds)));

        if (! $orderIds) {
            return [];
        }

        $orders = $this->orderRepository->findWhereIn('id', $orderIds)
            ->sortByDesc('created_at')
            ->take(20)
            ->values();

        $result = [];

        foreach ($orders as $order) {
            $result[] = $this->orderArray($order);
        }

        return $result;
    }

    public function statusStep(string $status): int
    {
        return match ($status) {
            'pending', 'pending_payment', 'pending_confirmation' => 0,
            'confirmed', 'processing' => 1,
            'out_for_delivery' => 2,
            'delivered', 'completed', 'paid', 'closed' => 3,
            default => -1,
        };
    }

    public function statusLabelKey(string $status): string
    {
        return match ($status) {
            'pending', 'pending_payment', 'pending_confirmation' => 'tracking_status_pending',
            'confirmed', 'processing' => 'tracking_status_confirmed',
            'out_for_delivery' => 'tracking_status_out_for_delivery',
            'delivered', 'completed', 'paid', 'closed' => 'tracking_status_delivered',
            'canceled', 'fraud' => 'tracking_status_canceled',
            'returned' => 'tracking_status_returned',
            default => 'tracking_status_pending',
        };
    }

    /**
     * One order as a plain array for the tracking page: status,
     * timeline dates from the status history, totals, and items —
     * with products Simo hid from tracking removed from the list.
     * An order whose items are all hidden still shows (status and
     * total), so the customer can always track their order.
     */
    protected function orderArray($order): array
    {
        $history = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->get(['new_status', 'created_at']);

        $at = function (array $statuses) use ($history) {
            $row = $history->first(fn ($h) => in_array($h->new_status, $statuses, true));

            return $row?->created_at;
        };

        $items = [];

        foreach ($order->items as $item) {
            $productId = (int) ($item->product_id ?? 0);

            if ($productId > 0 && ! $this->showInTracking($productId)) {
                continue;
            }

            $image = null;

            if ($productId > 0) {
                $product = $this->productRepository->find($productId);

                if ($product) {
                    $urls = ProductImage::getProductBaseImage($product);
                    $image = $urls['small_image_url'] ?? ($urls['medium_image_url'] ?? ($urls['original_image_url'] ?? null));
                }
            }

            $items[] = [
                'name' => $item->name,
                'qty' => (int) $item->qty_ordered,
                'price' => (float) $item->price,
                'formatted_price' => core()->formatPrice((float) $item->price),
                'total' => (float) $item->total,
                'formatted_total' => core()->formatPrice((float) $item->total),
                'image' => $image,
            ];
        }

        $status = (string) $order->status;

        return [
            'id' => (int) $order->id,
            'increment_id' => $order->increment_id,
            'status' => $status,
            'status_key' => $this->statusLabelKey($status),
            'step' => $this->statusStep($status),
            'created_at' => $order->created_at,
            'formatted_created_at' => $order->created_at->format('d/m/Y H:i'),
            'grand_total' => (float) $order->grand_total,
            'formatted_grand_total' => core()->formatPrice((float) $order->grand_total),
            'items' => $items,
            'timeline' => [
                ['key' => 'placed', 'at' => $order->created_at],
                ['key' => 'confirmed', 'at' => $at(['confirmed', 'processing'])],
                ['key' => 'out_for_delivery', 'at' => $at(['out_for_delivery'])],
                ['key' => 'delivered', 'at' => $at(['delivered', 'completed', 'paid'])],
            ],
        ];
    }
}
