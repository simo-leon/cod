<?php

namespace App\Cod\Analytics;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Models\CodOrder;
use App\Models\DeliveryParcel;
use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

class AnalyticsService implements AnalyticsServiceInterface
{
    /**
     * A confirmed order is one that left pending confirmation towards
     * delivery (or came back): confirmed, sent to delivery, delivered
     * or returned. A refused order becomes canceled and counts as
     * assigned but not confirmed.
     */
    public const CONFIRMED_STATUSES = [
        CodOrder::STATUS_CONFIRMED,
        CodOrder::STATUS_SENT_TO_DELIVERY,
        CodOrder::STATUS_DELIVERED,
        CodOrder::STATUS_RETURNED,
    ];

    /**
     * Orders that were shipped to a delivery provider (the delivery
     * fee applies to them): sent to delivery, delivered, or returned.
     */
    public const SHIPPED_STATUSES = [
        CodOrder::STATUS_SENT_TO_DELIVERY,
        CodOrder::STATUS_DELIVERED,
        CodOrder::STATUS_RETURNED,
    ];

    /**
     * A product with this many units or fewer in stock counts as low
     * stock. Stated in the analytics page so the rule is visible.
     */
    public const LOW_STOCK_THRESHOLD = 5;

    /**
     * A product needs at least this many orders before the analytics
     * page suggests scaling it up or stopping it.
     */
    public const MIN_ORDERS_FOR_DECISION = 3;

    public function __construct(
        protected OrderServiceInterface $orders,
        protected DeliveryServiceInterface $delivery,
        protected AssignmentServiceInterface $assignment,
        protected DeliveryProviderManager $providers,
    ) {}

    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int
    {
        return $this->orders->ordersCount();
    }

    /**
     * Number of parcels recorded in delivery_parcels, or null when the
     * table does not exist yet.
     */
    public function parcelsCount(): ?int
    {
        return $this->delivery->parcelsCount();
    }

    /**
     * Configured name of the COD System (Admin → Configuration → General
     * → COD System), default "COD System".
     */
    public function systemName(): string
    {
        $name = core()->getConfigData('general.cod_system.settings.system_name');

        return $name ? (string) $name : 'COD System';
    }

    /**
     * Confirmation and delivery rates of one confirmer:
     * ['admin', 'assigned', 'confirmed', 'delivered',
     * 'confirmation_rate', 'delivery_rate']. A rate is null when its
     * denominator is zero (no assigned orders, or no confirmed order).
     */
    public function confirmerRate(Admin $admin): array
    {
        $assigned = CodOrder::query()->where('assigned_to', $admin->id)->count();

        $confirmed = CodOrder::query()
            ->where('assigned_to', $admin->id)
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->count();

        $delivered = CodOrder::query()
            ->where('assigned_to', $admin->id)
            ->where('status', CodOrder::STATUS_DELIVERED)
            ->count();

        return [
            'admin' => $admin,
            'assigned' => $assigned,
            'confirmed' => $confirmed,
            'delivered' => $delivered,
            'confirmation_rate' => $assigned > 0 ? round($confirmed / $assigned * 100, 1) : null,
            'delivery_rate' => $confirmed > 0 ? round($delivered / $confirmed * 100, 1) : null,
        ];
    }

    /**
     * The same rates for every confirmer, ordered by name.
     */
    public function confirmerRates(): array
    {
        return $this->assignment->confirmers()
            ->map(fn (Admin $admin) => $this->confirmerRate($admin))
            ->all();
    }

    /**
     * Commission (DH) paid to a confirmer per delivered order, from
     * the COD System analytics settings — or null when it was never
     * entered in the admin.
     */
    public function commissionPerDeliveredOrder(): ?float
    {
        return $this->moneySetting('general.cod_system.analytics.confirmer_commission_per_delivered_order');
    }

    /**
     * Default delivery cost (DH) per order sent to delivery, from the
     * COD System analytics settings — or null when it was never
     * entered in the admin.
     */
    public function defaultDeliveryCost(): ?float
    {
        return $this->moneySetting('general.cod_system.analytics.default_delivery_cost');
    }

    /**
     * Default product cost (DH) per unit, from the COD System
     * analytics settings — or null when it was never entered.
     */
    public function defaultProductCost(): ?float
    {
        return $this->moneySetting('general.cod_system.analytics.default_product_cost');
    }

    /**
     * Per-SKU product costs (DH per unit) from the COD System
     * analytics settings: ['SKU' => cost]. One line per SKU,
     * "SKU=cost". Empty when none entered.
     */
    public function productCosts(): array
    {
        $raw = core()->getConfigData('general.cod_system.analytics.product_costs');

        $costs = [];

        if (! is_string($raw) || trim($raw) === '') {
            return $costs;
        }

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$sku, $cost] = explode('=', $line, 2);

            $sku = trim($sku);
            $cost = trim($cost);

            if ($sku === '' || ! is_numeric($cost) || (float) $cost < 0) {
                continue;
            }

            $costs[$sku] = (float) $cost;
        }

        return $costs;
    }

    /**
     * Cost (DH) of one unit of a SKU: the per-SKU cost when set,
     * otherwise the default product cost, otherwise null.
     */
    public function productCost(string $sku): ?float
    {
        return $this->productCosts()[$sku] ?? $this->defaultProductCost();
    }

    /**
     * Full analytics for the COD analytics page. See the interface for
     * the filter format and the no-invented-numbers rule: every figure
     * comes from real COD data or from admin settings, and a figure
     * without data is null.
     */
    public function analytics(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $orders = $this->filteredOrders($filters);

        // Items of every filtered order, once: used by the product
        // table and by the profit calculation.
        $itemsByOrder = [];

        foreach ($orders as $order) {
            $itemsByOrder[$order->id] = $this->orderItems($order);
        }

        $settings = [
            'commission' => $this->commissionPerDeliveredOrder(),
            'delivery_cost' => $this->defaultDeliveryCost(),
            'default_product_cost' => $this->defaultProductCost(),
            'product_costs' => $this->productCosts(),
        ];

        return [
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
            'overview' => $this->overview($orders),
            'by_city' => $this->byCity($orders),
            'by_provider' => $this->byProvider($orders),
            'products' => $this->productAnalytics($orders, $itemsByOrder),
            'profit' => $this->profit($orders, $itemsByOrder, $settings),
            'confirmers' => $this->confirmerAnalytics($orders, $settings['commission']),
            'inventory' => $this->inventory(),
            'settings' => $settings,
            'currency' => $this->currencyOf($orders),
        ];
    }

    /**
     * Keep only known filters, with dates as Y-m-d strings or null.
     */
    protected function normalizeFilters(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return [
            'from' => is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null,
            'to' => is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null,
            'city' => isset($filters['city']) && $filters['city'] !== '' ? (string) $filters['city'] : null,
            'provider' => isset($filters['provider']) && $filters['provider'] !== '' ? (string) $filters['provider'] : null,
            'sku' => isset($filters['sku']) && $filters['sku'] !== '' ? (string) $filters['sku'] : null,
        ];
    }

    /**
     * COD orders matching the filters. The SKU filter cannot be done
     * in SQL — items live in the linked store order or in the JSON
     * payload — so it filters the loaded collection in PHP.
     */
    protected function filteredOrders(array $filters): Collection
    {
        if (! Schema::hasTable('cod_orders')) {
            return collect();
        }

        $query = CodOrder::query()->with(['order.items', 'assignedTo']);

        if ($filters['from']) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($filters['to']) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if ($filters['city']) {
            $query->where('city', $filters['city']);
        }

        if ($filters['provider']) {
            if (! Schema::hasTable('delivery_parcels')) {
                return collect();
            }

            $provider = $filters['provider'];

            $query->whereExists(function ($exists) use ($provider) {
                $exists->select(DB::raw(1))
                    ->from('delivery_parcels')
                    ->whereColumn('delivery_parcels.order_id', 'cod_orders.order_id')
                    ->where('delivery_parcels.provider', $provider);
            });
        }

        $orders = $query->latest()->get();

        if ($filters['sku']) {
            $sku = $filters['sku'];

            $orders = $orders->filter(function (CodOrder $order) use ($sku) {
                foreach ($this->orderItems($order) as $item) {
                    if ($item['sku'] === $sku) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $orders;
    }

    /**
     * Values for the filter dropdowns: every city, provider and SKU
     * that exists in the COD data (unfiltered, so a filter never hides
     * the option that would clear it).
     */
    protected function filterOptions(): array
    {
        $cities = [];

        if (Schema::hasTable('cod_orders')) {
            $cities = CodOrder::query()
                ->whereNotNull('city')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->all();
        }

        $providers = [];

        if (Schema::hasTable('delivery_parcels')) {
            $providers = DeliveryParcel::query()
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider')
                ->map(fn ($name) => [
                    'name' => $name,
                    'label' => $this->providers->provider($name)?->getLabel() ?? $name,
                ])
                ->all();
        }

        $skus = [];

        if (Schema::hasTable('cod_orders')) {
            foreach (CodOrder::query()->with(['order.items'])->get() as $order) {
                foreach ($this->orderItems($order) as $item) {
                    $skus[$item['sku']] = $item['name'] !== '' ? $item['name'] : $item['sku'];
                }
            }

            ksort($skus);
        }

        return ['cities' => $cities, 'providers' => $providers, 'skus' => $skus];
    }

    /**
     * Totals, counts by status and by source, revenue and the three
     * headline rates. Revenue is the grand total of delivered orders
     * only — a delivered COD order is a paid order.
     */
    protected function overview(Collection $orders): array
    {
        $byStatus = array_fill_keys(CodOrder::STATUSES, 0);

        foreach ($orders as $order) {
            $byStatus[$order->status] = ($byStatus[$order->status] ?? 0) + 1;
        }

        $bySource = [];

        foreach ($orders as $order) {
            $bySource[$order->source] = ($bySource[$order->source] ?? 0) + 1;
        }

        ksort($bySource);

        $assigned = $orders->whereNotNull('assigned_to')->count();
        $confirmed = $orders->whereIn('status', self::CONFIRMED_STATUSES)->count();
        $delivered = $orders->where('status', CodOrder::STATUS_DELIVERED)->count();
        $returned = $orders->where('status', CodOrder::STATUS_RETURNED)->count();

        return [
            'total' => $orders->count(),
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'revenue' => (float) $orders->where('status', CodOrder::STATUS_DELIVERED)->sum('grand_total'),
            'confirmation_rate' => self::rate($confirmed, $assigned),
            'delivery_rate' => self::rate($delivered, $confirmed),
            // Returned out of the orders that reached a delivery
            // outcome (delivered or returned).
            'return_rate' => self::rate($returned, $delivered + $returned),
        ];
    }

    /**
     * One row per city: orders, confirmed, delivered, delivery rate
     * (delivered / confirmed) and revenue (delivered grand total).
     * Orders without a city are grouped under null.
     */
    protected function byCity(Collection $orders): array
    {
        $rows = [];

        foreach ($orders as $order) {
            $city = $order->city ?: null;

            $rows[$city] ??= [
                'city' => $city,
                'orders' => 0,
                'confirmed' => 0,
                'delivered' => 0,
                'revenue' => 0.0,
            ];

            $rows[$city]['orders']++;

            if (in_array($order->status, self::CONFIRMED_STATUSES, true)) {
                $rows[$city]['confirmed']++;
            }

            if ($order->status === CodOrder::STATUS_DELIVERED) {
                $rows[$city]['delivered']++;
                $rows[$city]['revenue'] += (float) $order->grand_total;
            }
        }

        foreach ($rows as &$row) {
            $row['delivery_rate'] = self::rate($row['delivered'], $row['confirmed']);
        }

        unset($row);

        usort($rows, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        return array_values($rows);
    }

    /**
     * One row per delivery provider: parcels successfully sent, the
     * distinct COD orders they belong to, how many of those were
     * delivered, and the delivery rate. Parcels of orders outside the
     * filters are excluded; a parcel that failed to send is not
     * counted as shipped.
     */
    protected function byProvider(Collection $orders): array
    {
        if (! Schema::hasTable('delivery_parcels')) {
            return [];
        }

        $orderIds = $orders->pluck('order_id')->filter()->all();

        if (empty($orderIds)) {
            return [];
        }

        $parcels = DeliveryParcel::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', 'sent')
            ->get();

        $byOrderId = $orders->keyBy('order_id');

        $rows = [];

        foreach ($parcels as $parcel) {
            $codOrder = $byOrderId->get($parcel->order_id);

            if (! $codOrder) {
                continue;
            }

            $rows[$parcel->provider] ??= [
                'provider' => $parcel->provider,
                'label' => $this->providers->provider($parcel->provider)?->getLabel() ?? $parcel->provider,
                'parcels' => 0,
                // Order id => whether that order was delivered, so one
                // order with several parcels is counted once.
                'order_ids' => [],
            ];

            $rows[$parcel->provider]['parcels']++;
            $rows[$parcel->provider]['order_ids'][$codOrder->id] = $codOrder->status === CodOrder::STATUS_DELIVERED;
        }

        foreach ($rows as &$row) {
            $row['orders'] = count($row['order_ids']);
            $row['delivered'] = count(array_filter($row['order_ids']));
            $row['delivery_rate'] = self::rate($row['delivered'], $row['orders']);

            unset($row['order_ids']);
        }

        unset($row);

        usort($rows, fn ($a, $b) => $b['parcels'] <=> $a['parcels']);

        return array_values($rows);
    }

    /**
     * One row per SKU found in the filtered orders: orders containing
     * it, confirmation and delivery rates, average unit price (from
     * the order items when recorded), unit cost (admin settings) and
     * profit per unit — null when the price or the cost is not known —
     * plus a suggestion from the rates only (see the page for the
     * rule).
     */
    protected function productAnalytics(Collection $orders, array $itemsByOrder): array
    {
        $rows = [];

        foreach ($orders as $order) {
            foreach ($itemsByOrder[$order->id] ?? [] as $item) {
                $sku = $item['sku'];

                $rows[$sku] ??= [
                    'sku' => $sku,
                    'name' => $item['name'],
                    'orders' => 0,
                    'confirmed' => 0,
                    'delivered' => 0,
                    'delivered_qty' => 0,
                    'price_sum' => 0.0,
                    'price_qty' => 0,
                ];

                $rows[$sku]['orders']++;

                if ($item['name'] !== '' && $rows[$sku]['name'] === '') {
                    $rows[$sku]['name'] = $item['name'];
                }

                if (in_array($order->status, self::CONFIRMED_STATUSES, true)) {
                    $rows[$sku]['confirmed']++;
                }

                if ($order->status === CodOrder::STATUS_DELIVERED) {
                    $rows[$sku]['delivered']++;
                    $rows[$sku]['delivered_qty'] += $item['qty'];
                }

                if ($item['price'] !== null) {
                    $rows[$sku]['price_sum'] += $item['price'] * $item['qty'];
                    $rows[$sku]['price_qty'] += $item['qty'];
                }
            }
        }

        $costs = $this->productCosts();
        $defaultCost = $this->defaultProductCost();

        foreach ($rows as &$row) {
            $row['confirmation_rate'] = self::rate($row['confirmed'], $row['orders']);
            $row['delivery_rate'] = self::rate($row['delivered'], $row['confirmed']);
            $row['unit_price'] = $row['price_qty'] > 0
                ? round($row['price_sum'] / $row['price_qty'], 2)
                : null;
            $row['unit_cost'] = $costs[$row['sku']] ?? $defaultCost;
            $row['profit_per_unit'] = $row['unit_price'] !== null && $row['unit_cost'] !== null
                ? round($row['unit_price'] - $row['unit_cost'], 2)
                : null;
            $row['suggestion'] = $this->productSuggestion($row);

            unset($row['price_sum'], $row['price_qty']);
        }

        unset($row);

        usort($rows, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        return array_values($rows);
    }

    /**
     * The product decision, from the rates only:
     * fewer than 3 orders → not enough data; delivery ≥ 70% and
     * confirmation ≥ 70% → scale up; delivery < 40% → stop / review;
     * otherwise → watch.
     */
    protected function productSuggestion(array $row): string
    {
        if ($row['orders'] < self::MIN_ORDERS_FOR_DECISION) {
            return 'not-enough-data';
        }

        if (
            $row['delivery_rate'] !== null && $row['delivery_rate'] >= 70
            && $row['confirmation_rate'] !== null && $row['confirmation_rate'] >= 70
        ) {
            return 'scale';
        }

        if ($row['delivery_rate'] !== null && $row['delivery_rate'] < 40) {
            return 'stop';
        }

        return 'watch';
    }

    /**
     * Net profit = revenue − product costs − confirmer commissions −
     * delivery costs − returns. Each component is null when its input
     * is missing (product cost for a delivered item, commission or
     * delivery cost never entered in the admin), and then the net
     * profit is null too; 'missing' names what must be entered.
     *
     * Delivery cost applies to shipped orders (sent to delivery and
     * delivered); the returns line is the delivery fee lost on
     * returned orders — it is not counted twice.
     */
    protected function profit(Collection $orders, array $itemsByOrder, array $settings): array
    {
        $revenue = (float) $orders->where('status', CodOrder::STATUS_DELIVERED)->sum('grand_total');

        $productCost = 0.0;
        $missingCost = false;

        foreach ($orders->where('status', CodOrder::STATUS_DELIVERED) as $order) {
            $items = $itemsByOrder[$order->id] ?? [];

            // A delivered order with no recorded items has an unknown
            // product cost — it must not silently count as zero.
            if (empty($items)) {
                $missingCost = true;

                continue;
            }

            foreach ($items as $item) {
                $cost = $settings['product_costs'][$item['sku']] ?? $settings['default_product_cost'];

                if ($cost === null) {
                    $missingCost = true;

                    continue;
                }

                $productCost += $item['qty'] * $cost;
            }
        }

        $deliveredCount = $orders->where('status', CodOrder::STATUS_DELIVERED)->count();
        $shippedCount = $orders->whereIn('status', [CodOrder::STATUS_SENT_TO_DELIVERY, CodOrder::STATUS_DELIVERED])->count();
        $returnedCount = $orders->where('status', CodOrder::STATUS_RETURNED)->count();

        $commissions = $settings['commission'] !== null
            ? round($deliveredCount * $settings['commission'], 2)
            : null;

        $deliveryCost = $settings['delivery_cost'] !== null
            ? round($shippedCount * $settings['delivery_cost'], 2)
            : null;

        $returnsCost = $settings['delivery_cost'] !== null
            ? round($returnedCount * $settings['delivery_cost'], 2)
            : null;

        $missing = [];

        if ($missingCost) {
            $missing[] = 'product-cost';
        }

        if ($commissions === null) {
            $missing[] = 'commission';
        }

        if ($deliveryCost === null) {
            $missing[] = 'delivery-cost';
        }

        $netProfit = null;

        if (! $missingCost && $commissions !== null && $deliveryCost !== null && $returnsCost !== null) {
            $netProfit = round($revenue - $productCost - $commissions - $deliveryCost - $returnsCost, 2);
        }

        return [
            'revenue' => $revenue,
            'product_cost' => $missingCost ? null : round($productCost, 2),
            'commissions' => $commissions,
            'delivery_cost' => $deliveryCost,
            'returns_cost' => $returnsCost,
            'net_profit' => $netProfit,
            'delivered_count' => $deliveredCount,
            'shipped_count' => $shippedCount,
            'returned_count' => $returnedCount,
            'missing' => $missing,
        ];
    }

    /**
     * One row per confirmer, from the filtered orders: assigned,
     * confirmed, delivered, both rates, and the commission due
     * (delivered × commission per delivered order — null when the
     * commission was never entered in the admin).
     */
    protected function confirmerAnalytics(Collection $orders, ?float $commission): array
    {
        $rows = [];

        foreach ($this->assignment->confirmers() as $admin) {
            $assigned = $orders->where('assigned_to', $admin->id);
            $confirmed = $assigned->whereIn('status', self::CONFIRMED_STATUSES)->count();
            $delivered = $assigned->where('status', CodOrder::STATUS_DELIVERED)->count();

            $rows[] = [
                'admin' => $admin,
                'assigned' => $assigned->count(),
                'confirmed' => $confirmed,
                'delivered' => $delivered,
                'confirmation_rate' => self::rate($confirmed, $assigned->count()),
                'delivery_rate' => self::rate($delivered, $confirmed),
                'commission_due' => $commission !== null ? round($delivered * $commission, 2) : null,
            ];
        }

        return $rows;
    }

    /**
     * Real store stock: every product with its total quantity across
     * inventory sources, its price, and its stock value (qty × price).
     * Low stock is flagged only for products that manage stock, at
     * the threshold stated on the page.
     */
    protected function inventory(): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('product_inventories')) {
            return [];
        }

        $rows = [];

        foreach (Product::query()->with(['inventories', 'attribute_values'])->orderBy('sku')->get() as $product) {
            $qty = (int) $product->inventories->sum('qty');
            $price = is_numeric($product->price) ? (float) $product->price : null;
            $managesStock = (bool) ($product->manage_stock ?? false);

            $rows[] = [
                'sku' => (string) $product->sku,
                'name' => (string) ($product->name ?? $product->sku),
                'qty' => $qty,
                'price' => $price,
                'value' => $price !== null ? round($qty * $price, 2) : null,
                'low_stock' => $managesStock && $qty <= self::LOW_STOCK_THRESHOLD,
            ];
        }

        return $rows;
    }

    /**
     * Items of one COD order: from its linked store order items, or
     * from the import payload when there is no linked store order.
     * Each item: ['sku', 'name', 'qty', 'price' (null when unknown)].
     */
    protected function orderItems(CodOrder $order): array
    {
        $items = [];

        if ($order->order) {
            foreach ($order->order->items as $item) {
                if (empty($item->sku)) {
                    continue;
                }

                $items[] = [
                    'sku' => (string) $item->sku,
                    'name' => (string) ($item->name ?? ''),
                    'qty' => max(1, (int) $item->qty_ordered),
                    'price' => is_numeric($item->price) ? (float) $item->price : null,
                ];
            }
        }

        if (empty($items) && is_array($order->payload)) {
            foreach ($order->payload['items'] ?? [] as $item) {
                if (! is_array($item) || empty($item['sku'])) {
                    continue;
                }

                $items[] = [
                    'sku' => (string) $item['sku'],
                    'name' => (string) ($item['name'] ?? ''),
                    'qty' => max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1)),
                    'price' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null,
                ];
            }
        }

        return $items;
    }

    /**
     * A rate in percent (one decimal), or null when the denominator is
     * zero — a rate with nothing behind it is unknown, not zero.
     */
    protected static function rate(?int $numerator, ?int $denominator): ?float
    {
        if (! $denominator) {
            return null;
        }

        return round(($numerator ?? 0) / $denominator * 100, 1);
    }

    /**
     * A money setting from core_config: null when empty, not a number
     * or negative — an unset value means "not entered", not zero.
     */
    protected function moneySetting(string $key): ?float
    {
        $value = core()->getConfigData($key);

        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * The currency of the filtered orders (the most common one), or
     * MAD when no order carries one.
     */
    protected function currencyOf(Collection $orders): string
    {
        $codes = $orders->pluck('currency_code')->filter()->countBy()->sortDesc();

        return $codes->keys()->first() ?? 'MAD';
    }
}
