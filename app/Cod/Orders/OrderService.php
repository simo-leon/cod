<?php

namespace App\Cod\Orders;

use App\Models\CodOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;

class OrderService implements OrderServiceInterface
{
    /**
     * Payment methods that count as cash on delivery.
     */
    public const COD_PAYMENT_METHODS = ['cashondelivery', 'cod'];

    /**
     * Store order status => COD workflow status.
     */
    public const STORE_STATUS_MAP = [
        Order::STATUS_PENDING_CONFIRMATION => CodOrder::STATUS_PENDING_CONFIRMATION,
        Order::STATUS_CONFIRMED => CodOrder::STATUS_CONFIRMED,
        Order::STATUS_CANCELED => CodOrder::STATUS_CANCELED,
        Order::STATUS_OUT_FOR_DELIVERY => CodOrder::STATUS_SENT_TO_DELIVERY,
        Order::STATUS_DELIVERED => CodOrder::STATUS_DELIVERED,
        Order::STATUS_RETURNED => CodOrder::STATUS_RETURNED,
        Order::STATUS_COMPLETED => CodOrder::STATUS_DELIVERED,
        Order::STATUS_PROCESSING => CodOrder::STATUS_CONFIRMED,
        Order::STATUS_PAID => CodOrder::STATUS_DELIVERED,
    ];

    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int
    {
        return Order::count();
    }

    /**
     * Number of orders waiting for confirmation.
     */
    public function pendingConfirmationCount(): int
    {
        return Order::where('status', Order::STATUS_PENDING_CONFIRMATION)->count();
    }

    /**
     * Number of orders created today.
     */
    public function todayOrdersCount(): int
    {
        return Order::whereDate('created_at', today())->count();
    }

    /**
     * Whether the order was paid cash on delivery.
     */
    public function isCodOrder(Order $order): bool
    {
        return in_array($order->payment?->method, self::COD_PAYMENT_METHODS, true);
    }

    /**
     * Record one store order in the COD System (webhook path).
     */
    public function importOrder(Order $order, string $source = CodOrder::SOURCE_WEBHOOK): ?CodOrder
    {
        if (! $this->isCodOrder($order)) {
            return null;
        }

        try {
            return $this->storeCodOrder(
                $this->snapshotFromOrder($order),
                $source,
            );
        } catch (\Throwable $e) {
            // An import failure must never break checkout.
            Log::warning('COD: failed to import order', [
                'order_id' => $order->id ?? null,
                'increment_id' => $order->increment_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record one order from an API payload.
     */
    public function importFromPayload(array $data, string $source = CodOrder::SOURCE_API): ?CodOrder
    {
        $externalId = trim((string) ($data['external_id'] ?? $data['increment_id'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        // A payload that matches a local store order takes its snapshot
        // from that order: the store is authoritative.
        $localOrder = Order::where('increment_id', $externalId)->first();

        if ($localOrder) {
            if (! $this->isCodOrder($localOrder)) {
                return null;
            }

            return $this->importOrder($localOrder, $source);
        }

        if (! empty($data['order_id'])) {
            $localOrder = Order::find((int) $data['order_id']);

            if ($localOrder && $this->isCodOrder($localOrder)) {
                return $this->importOrder($localOrder, $source);
            }
        }

        try {
            // Only fields present in the payload are written: a repeat
            // import with a partial payload must not wipe snapshot fields
            // (phone, city, ...) recorded by an earlier import.
            $snapshot = [
                'order_id' => null,
                'external_id' => $externalId,
                'payload' => $data,
            ];

            foreach ([
                'store_status' => 'status',
                'customer_name' => 'customer_name',
                'customer_phone' => 'customer_phone',
                'customer_email' => 'customer_email',
                'city' => 'city',
                'address' => 'address',
                'currency_code' => 'currency_code',
            ] as $snapshotKey => $dataKey) {
                if (array_key_exists($dataKey, $data)) {
                    $snapshot[$snapshotKey] = $data[$dataKey];
                }
            }

            if (array_key_exists('grand_total', $data)) {
                $snapshot['grand_total'] = (float) $data['grand_total'];
            }

            if (array_key_exists('items_count', $data)) {
                $snapshot['items_count'] = (int) $data['items_count'];
            }

            return $this->storeCodOrder($snapshot, $source);
        } catch (\Throwable $e) {
            Log::warning('COD: failed to import order from API payload', [
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Import every cash-on-delivery store order that has no COD record yet.
     */
    public function importPendingOrders(int $limit = 500): array
    {
        $imported = 0;
        $failed = 0;

        $alreadyImported = CodOrder::count();

        $orders = Order::whereHas('payment', function ($query) {
            $query->whereIn('method', self::COD_PAYMENT_METHODS);
        })
            ->whereNotIn('increment_id', CodOrder::query()->select('external_id'))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($orders as $order) {
            $codOrder = $this->importOrder($order, CodOrder::SOURCE_IMPORT);

            if ($codOrder) {
                $imported++;
            } else {
                $failed++;
            }
        }

        return [
            'imported' => $imported,
            'already_imported' => $alreadyImported,
            'failed' => $failed,
        ];
    }

    /**
     * COD workflow statuses, in display order.
     */
    public function statuses(): array
    {
        return CodOrder::STATUSES;
    }

    /**
     * Number of COD records per status.
     */
    public function statusCounts(): array
    {
        if (! Schema::hasTable('cod_orders')) {
            return [];
        }

        return CodOrder::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    /**
     * Imported COD orders, newest first, optionally filtered by status.
     */
    public function paginatedOrders(?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        if (! Schema::hasTable('cod_orders')) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return CodOrder::query()
            ->with('order')
            ->when(
                $status && in_array($status, CodOrder::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Secret key of the COD import endpoint, from the admin settings.
     */
    public function getImportSecretKey(): ?string
    {
        $key = core()->getConfigData('general.cod_system.settings.import_secret_key');

        return $key ? (string) $key : null;
    }

    /**
     * Snapshot fields of a store order, for the COD record.
     */
    protected function snapshotFromOrder(Order $order): array
    {
        $order->loadMissing(['payment', 'addresses']);

        $shippingAddress = $order->shipping_address ?? $order->billing_address;

        $customerName = trim($order->customer_first_name.' '.$order->customer_last_name);

        if ($customerName === '' && $shippingAddress) {
            $customerName = trim(($shippingAddress->first_name ?? '').' '.($shippingAddress->last_name ?? ''));
        }

        return [
            'order_id' => $order->id,
            'external_id' => $order->increment_id,
            'store_status' => $order->status,
            'customer_name' => $customerName !== '' ? $customerName : null,
            'customer_phone' => $shippingAddress->phone ?? null,
            'customer_email' => $order->customer_email ?? $shippingAddress->email ?? null,
            'city' => $shippingAddress->city ?? null,
            'address' => $shippingAddress->address ?? null,
            'grand_total' => (float) ($order->grand_total ?? 0),
            'currency_code' => $order->order_currency_code ?? null,
            'items_count' => (int) ($order->total_item_count ?? $order->items()->count()),
            'payload' => null,
        ];
    }

    /**
     * Create or refresh the single COD record for one store order.
     *
     * Idempotent on `external_id`: a second import of the same store order
     * updates the snapshot instead of creating a duplicate.
     *
     * The COD workflow status is synced from the store only while the
     * record is still pending confirmation. Once a confirmer moves it
     * forward, the COD System owns the status and the store status is kept
     * in `store_status` for reference only.
     */
    protected function storeCodOrder(array $snapshot, string $source): CodOrder
    {
        $mappedStatus = $this->mapStoreStatus($snapshot['store_status'] ?? null);

        $codOrder = CodOrder::firstOrNew(['external_id' => $snapshot['external_id']]);

        $isNew = ! $codOrder->exists;

        $codOrder->fill($snapshot);

        if ($isNew) {
            $codOrder->status = $mappedStatus;
            $codOrder->source = $source;
        } elseif ($codOrder->status === CodOrder::STATUS_PENDING_CONFIRMATION) {
            $codOrder->status = $mappedStatus;
        }

        $codOrder->save();

        return $codOrder;
    }

    /**
     * Map a store order status to a COD workflow status.
     */
    protected function mapStoreStatus(?string $storeStatus): string
    {
        return self::STORE_STATUS_MAP[$storeStatus] ?? CodOrder::STATUS_PENDING_CONFIRMATION;
    }
}
