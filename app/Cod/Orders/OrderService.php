<?php

namespace App\Cod\Orders;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\ConfirmationServiceInterface;
use App\Cod\Support\PhoneNumber;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\CodOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        protected AssignmentServiceInterface $assignment,
        protected ConfirmationServiceInterface $confirmation,
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

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
        // H3: a store order that is processing / paid / completed was
        // never confirmed by the customer (or a confirmer) — it must
        // NOT become confirmed/delivered in COD on re-import. Only a
        // store status of "confirmed" literally confirms, through the
        // gate in storeCodOrder() below.
        Order::STATUS_COMPLETED => CodOrder::STATUS_PENDING_CONFIRMATION,
        Order::STATUS_PROCESSING => CodOrder::STATUS_PENDING_CONFIRMATION,
        Order::STATUS_PAID => CodOrder::STATUS_PENDING_CONFIRMATION,
    ];

    /**
     * Total number of COD orders in the database (M1: the COD
     * dashboard used to count store orders here).
     */
    public function ordersCount(): int
    {
        return CodOrder::count();
    }

    /**
     * Number of COD orders waiting for confirmation.
     */
    public function pendingConfirmationCount(): int
    {
        return CodOrder::where('status', CodOrder::STATUS_PENDING_CONFIRMATION)->count();
    }

    /**
     * Number of COD orders created today.
     */
    public function todayOrdersCount(): int
    {
        return CodOrder::whereDate('created_at', today())->count();
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
    public function importFromPayload(array $data, string $source = CodOrder::SOURCE_API, bool $alreadyConfirmed = false): ?CodOrder
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

            if ($localOrder) {
                // A payload pointing at a local order that is not COD
                // must be rejected, like an increment_id match above.
                if (! $this->isCodOrder($localOrder)) {
                    return null;
                }

                return $this->importOrder($localOrder, $source);
            }
        }

        // External payloads (no matching local store order): when the
        // payload states a payment method, it must be cash on
        // delivery — a non-COD order (e.g. moneytransfer) is not a
        // COD order and must be rejected (the controller answers
        // 422). A payload without the field keeps the historic
        // behaviour: older payloads carry no payment method.
        if (
            array_key_exists('payment_method', $data)
            && $data['payment_method'] !== null
            && $data['payment_method'] !== ''
            && ! in_array($data['payment_method'], self::COD_PAYMENT_METHODS, true)
        ) {
            return null;
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

            return $this->storeCodOrder($snapshot, $source, $alreadyConfirmed);
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

        $orders = Order::whereHas('payment', function ($query) {
            $query->whereIn('method', self::COD_PAYMENT_METHODS);
        })
            ->whereNotIn('increment_id', CodOrder::query()->select('external_id'))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        // T13: count only the store orders this run skipped because a
        // COD record already exists — not every COD record in the
        // database (which also holds WhatsApp and API orders).
        $alreadyImported = Order::whereHas('payment', function ($query) {
            $query->whereIn('method', self::COD_PAYMENT_METHODS);
        })
            ->whereIn('increment_id', CodOrder::query()->select('external_id'))
            ->count();

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
     * Number of COD records per status, optionally only those
     * assigned to one confirmer.
     */
    public function statusCounts(?int $assignedTo = null): array
    {
        if (! Schema::hasTable('cod_orders')) {
            return [];
        }

        return CodOrder::query()
            ->when($assignedTo, fn ($query) => $query->where('assigned_to', $assignedTo))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    /**
     * Imported COD orders, newest first, optionally filtered by status
     * and/or by the confirmer they are assigned to. When $assignedTo
     * is given, unassigned orders are excluded too: a confirmer may
     * not open an unassigned order (the detail page 403s it), and
     * their task list only ever shows assigned orders.
     */
    public function paginatedOrders(?string $status = null, int $perPage = 20, ?int $assignedTo = null): LengthAwarePaginator
    {
        if (! Schema::hasTable('cod_orders')) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return CodOrder::query()
            ->with(['order', 'assignedTo'])
            ->when($assignedTo, fn ($query) => $query->where('assigned_to', $assignedTo))
            ->when(
                $status && in_array($status, CodOrder::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Assigned-order figures for one confirmer, for the COD dashboard:
     * ['total' => n, 'pending' => n, 'today' => n] — their assigned
     * orders, those still pending confirmation, and those assigned
     * today. Counts COD records, not store orders.
     */
    public function assignedOrderCounts(int $adminId): array
    {
        if (! Schema::hasTable('cod_orders')) {
            return ['total' => 0, 'pending' => 0, 'today' => 0];
        }

        return [
            'total' => CodOrder::query()->where('assigned_to', $adminId)->count(),
            'pending' => CodOrder::query()
                ->where('assigned_to', $adminId)
                ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
                ->count(),
            'today' => CodOrder::query()
                ->where('assigned_to', $adminId)
                ->whereDate('created_at', today())
                ->count(),
        ];
    }

    /**
     * Orders assigned to one confirmer, newest first, optionally
     * filtered by status. Used by the per-confirmer page.
     */
    public function paginatedOrdersForConfirmer(Admin $admin, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        if (! Schema::hasTable('cod_orders')) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return CodOrder::query()
            ->with(['order', 'callLogs'])
            ->where('assigned_to', $admin->id)
            ->when(
                $status && in_array($status, CodOrder::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Confirmation tasks: assigned orders, pending confirmation first,
     * then newest first. When a confirmer id is given, only that
     * confirmer's tasks are returned; otherwise every assigned order.
     */
    public function paginatedTasks(?int $confirmerId = null, int $perPage = 20): LengthAwarePaginator
    {
        if (! Schema::hasTable('cod_orders')) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return CodOrder::query()
            ->with(['order', 'assignedTo', 'callLogs'])
            ->whereNotNull('assigned_to')
            ->when($confirmerId, fn ($query) => $query->where('assigned_to', $confirmerId))
            ->orderByRaw("status = '".CodOrder::STATUS_PENDING_CONFIRMATION."' DESC")
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
            // N1a (round 2): in this Bagisto build the street lives
            // in the single `address` column — migration
            // 2024_03_07 renamed address1 → address, and there is
            // no address1 accessor — so reading address1/address2
            // (M8) always returned null. Read `address` first and
            // keep address1/address2 only as a fallback for builds
            // that still have the two columns.
            'address' => $this->streetAddress($shippingAddress),
            'grand_total' => (float) ($order->grand_total ?? 0),
            'currency_code' => $order->order_currency_code ?? null,
            'items_count' => (int) ($order->total_item_count ?? $order->items()->count()),
            'payload' => null,
        ];
    }

    /**
     * Street address of a Bagisto address, across builds: the
     * single `address` column first (this build), address1 (+
     * address2) as a fallback for older builds. See N1a.
     */
    protected function streetAddress(mixed $address): ?string
    {
        if (! $address) {
            return null;
        }

        $street = trim((string) ($address->address ?? ''));

        if ($street === '') {
            $street = trim(($address->address1 ?? '').' '.($address->address2 ?? ''));
        }

        return $street !== '' ? $street : null;
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
     *
     * When $alreadyConfirmed is true, the new record is confirmed right
     * away and the post-import block (assignment + confirmation message)
     * is skipped: the customer already confirmed (WhatsApp product
     * inquiry), so there is nothing to confirm and no confirmer to
     * assign.
     */
    protected function storeCodOrder(array $snapshot, string $source, bool $alreadyConfirmed = false): CodOrder
    {
        $mappedStatus = $this->mapStoreStatus($snapshot['store_status'] ?? null);

        $codOrder = CodOrder::firstOrNew(['external_id' => $snapshot['external_id']]);

        $isNew = ! $codOrder->exists;

        // M3: a re-import must never wipe snapshot data recorded by an
        // earlier import. The payload is deep-merged (items by SKU),
        // and a null payload from a store snapshot keeps the payload
        // recorded earlier instead of erasing it.
        if (! $isNew) {
            $snapshot['payload'] = $this->mergePayload(
                is_array($codOrder->payload) ? $codOrder->payload : null,
                $snapshot['payload'] ?? null
            );

            // Keep the normalized phone in step with the snapshot.
            if (array_key_exists('customer_phone', $snapshot)) {
                $snapshot['customer_phone_normalized'] = $snapshot['customer_phone']
                    ? PhoneNumber::normalize((string) $snapshot['customer_phone'])
                    : null;
            }
        } elseif (array_key_exists('customer_phone', $snapshot)) {
            $snapshot['customer_phone_normalized'] = $snapshot['customer_phone']
                ? PhoneNumber::normalize((string) $snapshot['customer_phone'])
                : null;
        }

        $codOrder->fill($snapshot);

        if ($isNew) {
            $codOrder->status = $alreadyConfirmed ? CodOrder::STATUS_CONFIRMED : $mappedStatus;
            $codOrder->source = $source;
        } elseif ($codOrder->status === CodOrder::STATUS_PENDING_CONFIRMATION) {
            // H3: sync the store status only towards non-confirmed
            // states. A pending order is never upgraded to confirmed /
            // delivered / sent-to-delivery by a re-import — only the
            // confirmation path (call / WhatsApp) may confirm it, and
            // only a store status of "confirmed" literally may sync
            // it to confirmed. A cancellation from the store does sync.
            $storeStatus = $snapshot['store_status'] ?? null;
            $maySync = in_array($mappedStatus, [CodOrder::STATUS_PENDING_CONFIRMATION, CodOrder::STATUS_CANCELED], true)
                || ($mappedStatus === CodOrder::STATUS_CONFIRMED && $storeStatus === Order::STATUS_CONFIRMED);

            if ($maySync) {
                $codOrder->status = $mappedStatus;
            }
        }

        $codOrder->save();

        // Phase 3: when automatic assignment is enabled, a newly
        // imported pending order is distributed to a confirmer right
        // away. A failure here must never break the import.
        if ($isNew && $codOrder->status === CodOrder::STATUS_PENDING_CONFIRMATION) {
            try {
                if ($this->confirmation->isAutoAssignmentEnabled()) {
                    $this->assignment->assign($codOrder);
                }
            } catch (\Throwable $e) {
                Log::warning('COD: auto-assignment failed on import', [
                    'cod_order_id' => $codOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 4: when the order could not be assigned (automatic
            // assignment off, or no confirmer available), the customer
            // still gets the WhatsApp confirmation message right away.
            // When assignment did happen, AssignmentService already
            // sent it — sendConfirmation() refuses to send twice.
            if (! $codOrder->assigned_to) {
                try {
                    $this->whatsapp->sendConfirmation($codOrder);
                } catch (\Throwable $e) {
                    Log::warning('COD: confirmation message failed on import', [
                        'cod_order_id' => $codOrder->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $codOrder;
    }

    /**
     * Merge an incoming import payload into the payload recorded
     * earlier, instead of replacing it (M3): a partial re-import
     * without `items` must keep the SKUs recorded before, and a
     * store snapshot (payload null) must keep an API payload recorded
     * earlier. Items merge by SKU — a re-imported item updates its
     * own row, other items stay. Any other key present in the new
     * payload overwrites the old one.
     */
    protected function mergePayload(?array $existing, mixed $incoming): ?array
    {
        if ($incoming === null) {
            return $existing;
        }

        if (! is_array($incoming)) {
            return $existing;
        }

        if ($existing === null) {
            return $incoming;
        }

        $merged = $existing;

        foreach ($incoming as $key => $value) {
            if ($key === 'items' && is_array($value) && isset($merged['items']) && is_array($merged['items'])) {
                $bySku = [];

                foreach ($merged['items'] as $item) {
                    $bySku[is_array($item) && ! empty($item['sku']) ? (string) $item['sku'] : count($bySku)] = $item;
                }

                foreach ($value as $item) {
                    if (is_array($item) && ! empty($item['sku'])) {
                        $bySku[(string) $item['sku']] = $item;
                    } else {
                        $bySku[] = $item;
                    }
                }

                $merged['items'] = array_values($bySku);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Map a store order status to a COD workflow status.
     */
    protected function mapStoreStatus(?string $storeStatus): string
    {
        return self::STORE_STATUS_MAP[$storeStatus] ?? CodOrder::STATUS_PENDING_CONFIRMATION;
    }
}
