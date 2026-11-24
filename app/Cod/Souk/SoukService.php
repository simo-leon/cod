<?php

namespace App\Cod\Souk;

use App\Models\CodOrder;
use App\Models\SoukPayment;
use App\Models\SoukProductSourcing;
use App\Models\SoukTask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Inventory\Models\InventorySource;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductFlat;
use Webkul\Product\Models\ProductInventory;
use Webkul\Sales\Models\OrderItem;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

class SoukService implements SoukServiceInterface
{
    public function __construct(
        protected SoukSettings $settings,
    ) {}

    /**
     * The sourcing row of one product, if it was ever set.
     */
    public function sourcingFor(int $productId): ?SoukProductSourcing
    {
        return SoukProductSourcing::query()->where('product_id', $productId)->first();
    }

    /**
     * Set (or create) the sourcing mode of one product, with its
     * optional price cap and minimum margin.
     */
    public function setSourcing(int $productId, string $mode, ?float $priceCap, ?float $minMarginPercent): SoukProductSourcing
    {
        if (! in_array($mode, SoukProductSourcing::MODES, true)) {
            $mode = SoukProductSourcing::MODE_AUTO;
        }

        return SoukProductSourcing::updateOrCreate(
            ['product_id' => $productId],
            [
                'mode' => $mode,
                'price_cap' => $priceCap,
                'min_margin_percent' => $minMarginPercent,
            ],
        );
    }

    /**
     * The mode that actually applies to a product right now: an
     * explicit mode wins; "auto" resolves to "souk" when the stock
     * is zero and to "own_stock" otherwise. A product without a
     * sourcing row behaves like "auto".
     */
    public function effectiveMode(Product $product): string
    {
        $sourcing = $this->sourcingFor((int) $product->id);

        $mode = $sourcing?->mode ?? SoukProductSourcing::MODE_AUTO;

        if ($mode === SoukProductSourcing::MODE_AUTO) {
            return $this->stockQty((int) $product->id) <= 0
                ? SoukProductSourcing::MODE_SOUK
                : SoukProductSourcing::MODE_OWN_STOCK;
        }

        return $mode;
    }

    /**
     * Available quantity of a product: what is physically in the
     * inventory sources minus what is already reserved by open
     * orders (product_ordered_inventories). An order never
     * decrements product_inventories directly — it reserves — so
     * reading product_inventories alone would never reach zero.
     * Products without any inventory row count as zero.
     */
    public function stockQty(int $productId): int
    {
        $onHand = (int) ProductInventory::query()->where('product_id', $productId)->sum('qty');

        $ordered = (int) DB::table('product_ordered_inventories')
            ->where('product_id', $productId)
            ->sum('qty');

        return $onHand - $ordered;
    }

    /**
     * Sale price of one unit: the price the customer actually paid.
     *
     * When the task is linked to a COD order, the order item's own
     * unit price is the source of truth — it is what the customer
     * was charged, special price included. Otherwise the active
     * special price of the default-channel product flat row wins
     * over the list price; a special price outside its date window
     * (or not below the list price) is ignored.
     */
    public function salePrice(int $productId, ?int $codOrderId = null): ?float
    {
        if ($codOrderId !== null) {
            $orderPrice = $this->orderItemPrice($productId, $codOrderId);

            if ($orderPrice !== null) {
                return $orderPrice;
            }
        }

        // M24: the store's real default channel, not the literal
        // string 'default' (a renamed default channel broke the price).
        $flat = ProductFlat::query()
            ->where('product_id', $productId)
            ->where('channel', $this->defaultChannelCode())
            ->first()
            ?? ProductFlat::query()->where('product_id', $productId)->first();

        if ($flat === null || $flat->price === null) {
            return null;
        }

        $price = (float) $flat->price;
        $special = $flat->special_price !== null ? (float) $flat->special_price : null;

        if ($special !== null && $special > 0 && $special < $price && $this->specialPriceIsActive($flat)) {
            return $special;
        }

        return $price;
    }

    /**
     * The per-unit price charged for one product on the store order
     * behind a COD order, or null when the order (or its item) is
     * not found. `order_items.price` is the unit price.
     */
    protected function orderItemPrice(int $productId, int $codOrderId): ?float
    {
        $codOrder = CodOrder::query()->find($codOrderId);

        if (! $codOrder || ! $codOrder->order_id) {
            return null;
        }

        $item = OrderItem::query()
            ->where('order_id', $codOrder->order_id)
            ->where('product_id', $productId)
            ->first();

        if (! $item || (float) $item->price <= 0) {
            return null;
        }

        return (float) $item->price;
    }

    /**
     * Whether the flat row's special price applies today: inside
     * its from/to window (either bound may be empty).
     */
    protected function specialPriceIsActive(ProductFlat $flat): bool
    {
        $today = Carbon::today();

        if ($flat->special_price_from !== null && Carbon::parse($flat->special_price_from)->startOfDay()->greaterThan($today)) {
            return false;
        }

        if ($flat->special_price_to !== null && Carbon::parse($flat->special_price_to)->startOfDay()->lessThan($today)) {
            return false;
        }

        return true;
    }

    /**
     * The live task of one product, if any.
     */
    public function openTaskForProduct(int $productId): ?SoukTask
    {
        return SoukTask::query()
            ->where('product_id', $productId)
            ->whereIn('status', SoukTask::OPEN_STATUSES)
            ->orderBy('id')
            ->first();
    }

    /**
     * The products a COD order needs from the souk, as
     * [['product' => Product, 'qty' => int], ...] — from the linked
     * store order's items when there is one, otherwise from the
     * payload items (WhatsApp/API orders have no store order; their
     * items live in payload.items, matched by SKU — the same source
     * the 30-minute scan already uses, M11). N2: the confirmation
     * listener and the uncovered counter share this instead of
     * assuming a store order exists.
     *
     * @return array<int, array{product: Product, qty: int}>
     */
    public function itemsForCodOrder(CodOrder $codOrder): array
    {
        $items = [];

        $storeOrder = $codOrder->order_id ? $codOrder->order : null;

        if ($storeOrder) {
            foreach ($storeOrder->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $items[] = ['product' => $product, 'qty' => max(1, (int) ($item->qty_ordered ?? 1))];
            }

            return $items;
        }

        $payload = is_array($codOrder->payload) ? $codOrder->payload : [];

        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item) || empty($item['sku'])) {
                continue;
            }

            $product = Product::query()->where('sku', (string) $item['sku'])->first();

            if (! $product) {
                continue;
            }

            $items[] = ['product' => $product, 'qty' => max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1))];
        }

        return $items;
    }

    /**
     * Make sure a product that has to come from the souk has a live
     * task on the panel. Returns the existing live task, the new
     * task, or null when the product does not need the souk (own
     * stock, or the panel is switched off) — never throws, so a
     * listener or a scheduled scan can never break a checkout.
     *
     * The sourcing row is locked inside the transaction, so two
     * concurrent calls for the same product can never create two
     * live tasks.
     */
    public function ensureTaskForProduct(Product $product, ?int $codOrderId = null, int $qty = 1): ?SoukTask
    {
        if (! $this->settings->isEnabled()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($product, $codOrderId, $qty) {
                // S2: the confirmation gate ran before this transaction
                // — re-check the linked order on its locked row, so an
                // order cancelled between the gate and the creation
                // cannot get a task.
                if ($codOrderId !== null) {
                    $linkedOrder = CodOrder::query()->where('id', $codOrderId)->lockForUpdate()->first();

                    if (! $linkedOrder || $linkedOrder->status !== CodOrder::STATUS_CONFIRMED) {
                        return null;
                    }
                }

                $sourcing = SoukProductSourcing::query()
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $sourcing) {
                    $sourcing = SoukProductSourcing::create([
                        'product_id' => $product->id,
                        'mode' => SoukProductSourcing::MODE_AUTO,
                    ]);
                }

                $mode = $sourcing->mode === SoukProductSourcing::MODE_AUTO
                    ? ($this->stockQty((int) $product->id) <= 0
                        ? SoukProductSourcing::MODE_SOUK
                        : SoukProductSourcing::MODE_OWN_STOCK)
                    : $sourcing->mode;

                if ($mode !== SoukProductSourcing::MODE_SOUK) {
                    return null;
                }

                $existing = $this->openTaskForProduct((int) $product->id);

                if ($existing) {
                    if ($codOrderId && ! $existing->cod_order_id) {
                        $existing->cod_order_id = $codOrderId;
                        $existing->save();
                    } elseif ($codOrderId && $existing->cod_order_id !== $codOrderId) {
                        // S3: the live task belongs to another order —
                        // it does not cover this one. Expand its
                        // quantity so the goods for both orders are
                        // bought, and record why.
                        $existing->qty = (int) $existing->qty + max(1, $qty);
                        $existing->save();

                        Log::info('Souk: live task expanded to cover a second confirmed order', [
                            'souk_task_id' => $existing->id,
                            'product_id' => (int) $product->id,
                            'cod_order_id' => $codOrderId,
                        ]);
                    }

                    return $existing;
                }

                return SoukTask::create([
                    'product_id' => $product->id,
                    'cod_order_id' => $codOrderId,
                    'qty' => max(1, $qty),
                    'status' => SoukTask::STATUS_ASSIGNED,
                    'sale_price' => $this->salePrice((int) $product->id, $codOrderId),
                    'deadline_at' => now()->addHours($this->settings->claimTimeoutHours()),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('Souk: failed to ensure task for product', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Make sure a product that has to come from the souk has a live
     * task — but only when a confirmed COD order still needs it.
     * Returns the task, or null when no confirmed order needs the
     * product. This is the shared confirmation gate for the
     * automatic paths (the product-update listener and the
     * 30-minute scan): neither may create a task for a product
     * nobody ordered.
     */
    public function ensureTaskForConfirmedOrder(Product $product): ?SoukTask
    {
        $needing = $this->confirmedOrderNeedingSouk((int) $product->id);

        if ($needing === null) {
            return null;
        }

        [$codOrderId, $qty] = $needing;

        $task = $this->ensureTaskForProduct($product, $codOrderId, $qty);

        // S3 (gateway): the gate above returns only the oldest
        // order, so a task created for it never grew when a second
        // confirmed order needed the same product. Expand the live
        // task to the total still needed by all confirmed orders.
        if ($task !== null) {
            $this->expandTaskToTotalNeeded($product, $task);
        }

        return $task;
    }

    /**
     * Total quantity of one product still needed by confirmed COD
     * orders whose goods have not been brought in yet (no PAID task
     * for the product): store orders via order_items plus
     * WhatsApp/API orders via payload items.
     */
    protected function totalQtyNeedingSouk(int $productId): int
    {
        $total = (int) DB::table('cod_orders as c')
            ->join('order_items as oi', 'oi.order_id', '=', 'c.order_id')
            ->where('c.status', CodOrder::STATUS_CONFIRMED)
            ->where('oi.product_id', $productId)
            ->whereNotExists(function ($query) use ($productId) {
                $query->select(DB::raw(1))
                    ->from('souk_tasks as st')
                    ->whereColumn('st.cod_order_id', 'c.id')
                    ->where('st.product_id', $productId)
                    ->where('st.status', SoukTask::STATUS_PAID);
            })
            ->sum('oi.qty_ordered');

        $sku = Product::query()->where('id', $productId)->value('sku');

        if ($sku) {
            foreach ($this->whatsappOrdersNeedingProduct($productId) as $candidate) {
                foreach ((is_array($candidate->payload) ? ($candidate->payload['items'] ?? []) : []) as $item) {
                    if (is_array($item) && (string) ($item['sku'] ?? '') === (string) $sku) {
                        $total += max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1));
                        break;
                    }
                }
            }
        }

        return $total;
    }

    /**
     * S3 (gateway): a live task must cover every confirmed order
     * for the product, not just the oldest one the gate returned.
     * Bump its quantity up to the total still needed — never down
     * — so a second confirmed order for the same product expands
     * the open task instead of being ignored. Idempotent: once the
     * task quantity equals the total, later runs change nothing.
     */
    protected function expandTaskToTotalNeeded(Product $product, SoukTask $task): void
    {
        $total = $this->totalQtyNeedingSouk((int) $product->id);

        if ($total <= (int) $task->qty) {
            return;
        }

        try {
            DB::transaction(function () use ($task, $total, $product) {
                $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

                if (! $locked || (int) $locked->qty >= $total) {
                    return;
                }

                $locked->qty = $total;
                $locked->save();

                Log::info('Souk: live task expanded to cover all confirmed orders for the product', [
                    'souk_task_id' => $locked->id,
                    'product_id' => (int) $product->id,
                    'qty' => $total,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('Souk: failed to expand task to total needed', [
                'souk_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirmed WhatsApp/API orders (no store order) whose payload
     * needs one product and whose goods have not been brought in
     * yet (no PAID task for the product). Shared by the gate
     * (confirmedOrderNeedingSouk) and the total-needed count.
     *
     * @return Collection<int, CodOrder>
     */
    protected function whatsappOrdersNeedingProduct(int $productId): Collection
    {
        $sku = Product::query()->where('id', $productId)->value('sku');

        if (! $sku) {
            return new Collection;
        }

        return CodOrder::query()
            ->where('status', CodOrder::STATUS_CONFIRMED)
            ->whereNull('order_id')
            ->whereRaw("JSON_CONTAINS(payload, JSON_OBJECT('sku', ?), '$.items')", [(string) $sku])
            ->whereNotExists(function ($query) use ($productId) {
                $query->select(DB::raw(1))
                    ->from('souk_tasks as st')
                    ->whereColumn('st.cod_order_id', 'cod_orders.id')
                    ->where('st.product_id', $productId)
                    ->where('st.status', SoukTask::STATUS_PAID);
            })
            ->orderBy('id')
            ->get(['id', 'payload']);
    }

    /**
     * Scan every product whose stock is zero (or missing) and make
     * sure it has a live task when its sourcing mode sends it to the
     * souk — but only when a confirmed COD order still needs that
     * product. A product with no confirmed order never gets a task
     * from this scan: an unconfirmed order must never reach the
     * panel, and neither may a product that simply ran out of stock
     * with nobody waiting for it. Returns
     * ['checked' => n, 'created' => n].
     */
    public function checkZeroStock(): array
    {
        $checked = 0;
        $created = 0;

        // M23: bound the scan (200 products per run, in chunks) — a
        // big catalog used to make every scheduled run scan everything
        // with several queries per product.
        $productIds = DB::table('products as p')
            ->leftJoin('souk_product_sourcing as s', 's.product_id', '=', 'p.id')
            ->where(function ($query) {
                $query->whereNull('s.mode')
                    ->orWhereIn('s.mode', [SoukProductSourcing::MODE_AUTO, SoukProductSourcing::MODE_SOUK]);
            })
            ->whereRaw('(SELECT COALESCE(SUM(qty), 0) FROM product_inventories WHERE product_id = p.id) - (SELECT COALESCE(SUM(qty), 0) FROM product_ordered_inventories WHERE product_id = p.id) <= 0')
            ->orderBy('p.id')
            ->limit(200)
            ->pluck('p.id');

        foreach ($productIds->chunk(50) as $chunk) {
            foreach ($chunk as $productId) {
                $checked++;
                $product = Product::query()->find($productId);

                if (! $product) {
                    continue;
                }

                $hadOpenTask = $this->openTaskForProduct((int) $productId) !== null;

                if ($this->ensureTaskForConfirmedOrder($product) && ! $hadOpenTask) {
                    $created++;
                }
            }
        }

        return ['checked' => $checked, 'created' => $created];
    }

    /**
     * The oldest confirmed COD order that still needs one product
     * from the souk: its status is confirmed, its store order
     * contains the product, and no task for that order and product
     * has already brought the goods in (paid). Returns
     * [cod_order_id, qty] or null when no confirmed order needs it.
     *
     * An order whose task was rejected, expired or cancelled is not
     * excluded: rejection and expiry recreate a fresh task
     * themselves, and a cancelled task leaves the order still
     * waiting for its goods — so the scan recreates it here.
     */
    protected function confirmedOrderNeedingSouk(int $productId): ?array
    {
        $row = DB::table('cod_orders as c')
            ->join('order_items as oi', 'oi.order_id', '=', 'c.order_id')
            ->where('c.status', CodOrder::STATUS_CONFIRMED)
            ->where('oi.product_id', $productId)
            ->whereNotExists(function ($query) use ($productId) {
                $query->select(DB::raw(1))
                    ->from('souk_tasks as st')
                    ->whereColumn('st.cod_order_id', 'c.id')
                    ->where('st.product_id', $productId)
                    ->where('st.status', SoukTask::STATUS_PAID);
            })
            ->orderBy('c.id')
            ->select('c.id as cod_order_id', 'oi.qty_ordered')
            ->first();

        if ($row) {
            return [(int) $row->cod_order_id, max(1, (int) ($row->qty_ordered ?? 1))];
        }

        // M11: WhatsApp-created orders have no store order (order_id
        // is null) — their items live in payload.items. Match the
        // product by SKU in the payload instead of the order_items
        // join, so a confirmed WhatsApp order can reach the panel too.
        $sku = Product::query()->where('id', $productId)->value('sku');

        if (! $sku) {
            return null;
        }

        foreach ($this->whatsappOrdersNeedingProduct($productId) as $candidate) {
            $qty = 1;

            foreach ((is_array($candidate->payload) ? ($candidate->payload['items'] ?? []) : []) as $item) {
                if (is_array($item) && (string) ($item['sku'] ?? '') === (string) $sku) {
                    $qty = max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1));
                    break;
                }
            }

            return [(int) $candidate->id, $qty];
        }

        return null;
    }

    /**
     * Claim an assigned task: the first buyer wins. The task row is
     * locked inside the transaction and its status is re-checked, so
     * two buyers claiming at the same instant cannot both win — the
     * second one gets false and sees the task as claimed.
     */
    public function claim(SoukTask $task, Admin $buyer): bool
    {
        if (! $this->canActAsBuyer($buyer)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($task, $buyer) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SoukTask::STATUS_ASSIGNED) {
                return false;
            }

            $locked->buyer_id = $buyer->id;
            $locked->claimed_at = now();
            $locked->status = SoukTask::STATUS_CLAIMED;
            $locked->save();

            return true;
        });
    }

    /**
     * Record the price the buyer paid in the souk for one unit.
     * Only the buyer who claimed the task (or a manager) may do it,
     * and only while the task is claimed. A price above the cap or a
     * margin below the minimum raises a visible flag — it does not
     * block the purchase, but the controller must see it and approve
     * it explicitly at verification.
     */
    public function recordPurchase(SoukTask $task, Admin $buyer, float $price, ?string $note = null): bool
    {
        if ($price <= 0) {
            return false;
        }

        if ($task->buyer_id !== $buyer->id && ! $this->canManageSouk($buyer)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($task, $price, $note) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SoukTask::STATUS_CLAIMED) {
                return false;
            }

            $locked->purchase_price = $price;
            $locked->purchased_at = now();
            $locked->status = SoukTask::STATUS_PURCHASED;

            if ($note !== null && trim($note) !== '') {
                $locked->note = trim($note);
            }

            $cap = $this->effectiveCap((int) $locked->product_id);
            $locked->over_cap = $cap !== null && $price > $cap;

            $minMargin = $this->effectiveMinMargin((int) $locked->product_id);
            $locked->below_margin = false;

            if ($minMargin !== null && $locked->sale_price !== null && (float) $locked->sale_price > 0) {
                $marginPercent = ((float) $locked->sale_price - $price) / (float) $locked->sale_price * 100;
                $locked->below_margin = $marginPercent < $minMargin;
            }

            $locked->save();

            return true;
        });
    }

    /**
     * Record that the goods entered the warehouse: the task moves to
     * received and the purchased quantity is added back to the
     * default inventory source, so the product is sellable again.
     * Only the buyer who claimed the task (or a manager) may do it,
     * and only while the task is purchased.
     */
    public function receive(SoukTask $task, Admin $buyer): bool
    {
        if ($task->buyer_id !== $buyer->id && ! $this->canManageSouk($buyer)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($task) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SoukTask::STATUS_PURCHASED) {
                return false;
            }

            // M24: add the stock to the source the product already
            // uses, not always 'default' — otherwise the stock splits
            // across two sources.
            $sourceId = ProductInventory::query()
                ->where('product_id', $locked->product_id)
                ->orderBy('id')
                ->value('inventory_source_id');

            $source = ($sourceId
                ? InventorySource::query()->where('id', $sourceId)->first()
                : null)
                ?? InventorySource::query()->where('code', 'default')->first()
                ?? InventorySource::query()->orderBy('id')->first();

            if ($source) {
                $inventory = ProductInventory::query()->firstOrNew([
                    'product_id' => $locked->product_id,
                    'inventory_source_id' => $source->id,
                ]);

                $inventory->qty = ((int) ($inventory->qty ?? 0)) + (int) $locked->qty;
                $inventory->save();

                $this->refreshInventoryIndex((int) $locked->product_id);
            }

            $locked->received_at = now();
            $locked->status = SoukTask::STATUS_RECEIVED;
            $locked->save();

            return true;
        });
    }

    /**
     * The warehouse controller accepts or refuses the received
     * goods. A refusal needs a reason, stays on the buyer's record,
     * and a fresh assigned task is created for the same product so
     * another buyer can take it. An approval only moves the task to
     * verified — payment is recorded separately.
     */
    public function verify(SoukTask $task, Admin $controller, bool $approve, ?string $reason = null, bool $overrideApproved = false): bool
    {
        if (! $this->canManageSouk($controller)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($task, $controller, $approve, $reason, $overrideApproved) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SoukTask::STATUS_RECEIVED) {
                return false;
            }

            $locked->controller_id = $controller->id;
            $locked->verified_at = now();

            if ($approve) {
                // M25: a purchase over the cap or below the margin was
                // only flagged before — the controller must approve the
                // overrun explicitly (checkbox), and the approval is
                // recorded on the task. Without it the approval is
                // refused, not silently accepted.
                if (($locked->over_cap || $locked->below_margin) && ! $overrideApproved) {
                    return false;
                }

                $locked->override_approved = $overrideApproved && ($locked->over_cap || $locked->below_margin);
                $locked->status = SoukTask::STATUS_VERIFIED;
                $locked->save();

                return true;
            }

            // A refusal without a reason is not recorded: the reason
            // is mandatory (the controller enforces it too), and no
            // default text is invented here.
            if ($reason === null || trim($reason) === '') {
                return false;
            }

            $locked->status = SoukTask::STATUS_REJECTED;
            $locked->rejection_reason = trim($reason);
            $locked->save();

            // The product is needed again: a fresh assigned task so
            // another buyer can take it — but only while the panel
            // is switched on, and (S4) only when the linked order is
            // still confirmed and still needs the product. The rejected
            // task stays as the history of this buyer's attempt.
            $product = Product::query()->find($locked->product_id);

            if ($product && $this->settings->isEnabled()) {
                if ($locked->cod_order_id) {
                    $this->ensureTaskForConfirmedOrder($product);
                } else {
                    $this->createAssignedTask($product, $locked->cod_order_id, (int) $locked->qty);
                }
            }

            return true;
        });
    }

    /**
     * Record one payment to the buyer (advance or reimbursement).
     * Only a manager records payments, only for a task that has a
     * buyer and a purchase price, and only while the task is live.
     * When the recorded payments cover the purchase price of a
     * verified task, the task becomes paid. Returns the payment, or
     * null when the payment could not be recorded.
     */
    public function recordPayment(SoukTask $task, Admin $paidBy, float $amount, string $type, ?string $note = null): ?SoukPayment
    {
        if (! $this->canManageSouk($paidBy)) {
            return null;
        }

        if ($amount <= 0 || ! in_array($type, SoukPayment::TYPES, true)) {
            return null;
        }

        if ($task->buyer_id === null || $task->purchase_price === null) {
            return null;
        }

        if (! in_array($task->status, [SoukTask::STATUS_CLAIMED, SoukTask::STATUS_PURCHASED, SoukTask::STATUS_RECEIVED, SoukTask::STATUS_VERIFIED], true)) {
            return null;
        }

        return DB::transaction(function () use ($task, $paidBy, $amount, $type, $note) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->buyer_id === null || $locked->purchase_price === null) {
                return null;
            }

            // H6: the pre-transaction status check ran on a stale
            // model — re-check it on the locked row, so a task that
            // was cancelled or paid in between cannot receive a
            // payment. And never pay more than the purchase in total:
            // the amount due is purchase_price x qty.
            if (! in_array($locked->status, [SoukTask::STATUS_CLAIMED, SoukTask::STATUS_PURCHASED, SoukTask::STATUS_RECEIVED, SoukTask::STATUS_VERIFIED], true)) {
                return null;
            }

            $alreadyPaid = (float) SoukPayment::query()->where('souk_task_id', $locked->id)->sum('amount');
            $totalDue = (float) $locked->purchase_price * max(1, (int) $locked->qty);

            if ($alreadyPaid + $amount > $totalDue + 0.001) {
                Log::warning('Souk: payment refused, amount exceeds the purchase total', [
                    'souk_task_id' => $locked->id,
                    'already_paid' => $alreadyPaid,
                    'attempted' => $amount,
                    'total_due' => $totalDue,
                ]);

                return null;
            }

            $payment = SoukPayment::create([
                'souk_task_id' => $locked->id,
                'buyer_id' => $locked->buyer_id,
                'paid_by' => $paidBy->id,
                'amount' => $amount,
                'type' => $type,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'paid_at' => now(),
            ]);

            if ($locked->status === SoukTask::STATUS_VERIFIED && $locked->amountDue() !== null && $locked->amountDue() <= 0) {
                $locked->status = SoukTask::STATUS_PAID;
                $locked->paid_at = now();
                $locked->save();
            }

            return $payment;
        });
    }

    /**
     * Cancel a task that is still assigned or claimed. A task that
     * was already purchased cannot be cancelled — the buyer already
     * spent money in the souk, so it must go through verification
     * (accept or refuse with a reason) instead.
     */
    public function cancel(SoukTask $task, Admin $admin, ?string $reason = null): bool
    {
        if (! $this->canManageSouk($admin)) {
            return false;
        }

        // M26: same pattern as claim/purchase/receive/verify — the
        // status is re-checked on the locked row inside a transaction,
        // so a cancel racing a verification cannot overwrite it.
        return (bool) DB::transaction(function () use ($task, $reason) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, [SoukTask::STATUS_ASSIGNED, SoukTask::STATUS_CLAIMED], true)) {
                return false;
            }

            $locked->status = SoukTask::STATUS_CANCELED;

            // T12: the cancel reason has its own column — it no longer
            // overwrites the purchase note.
            if ($reason !== null && trim($reason) !== '') {
                $locked->cancel_reason = trim($reason);
            }

            $locked->save();

            return true;
        });
    }

    /**
     * Escalate overdue tasks (scheduled, see souk:escalate):
     * - assigned tasks nobody claimed before the deadline expire and
     *   a fresh assigned task is created, so the product stays
     *   available and the missed deadline stays visible as an
     *   expired task plus an escalated flag on the new one;
     * - claimed tasks whose buyer never recorded a purchase before
     *   the deadline are flagged escalated (the buyer still owns
     *   the task; the controller may cancel it to free the product).
     *
     * Returns ['expired' => n, 'recreated' => n, 'escalated' => n].
     */
    public function escalateOverdue(): array
    {
        $expired = 0;
        $recreated = 0;
        $escalated = 0;

        $overdue = SoukTask::query()
            ->where('status', SoukTask::STATUS_ASSIGNED)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($overdue as $task) {
            DB::transaction(function () use ($task, &$expired, &$recreated) {
                $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== SoukTask::STATUS_ASSIGNED) {
                    return;
                }

                $locked->status = SoukTask::STATUS_EXPIRED;
                $locked->is_escalated = true;
                $locked->escalated_at = now();
                $locked->save();
                $expired++;

                $product = Product::query()->find($locked->product_id);

                if ($product && $this->settings->isEnabled()) {
                    // S4: only recreate when the linked order is still
                    // confirmed and still needs the product.
                    $fresh = $locked->cod_order_id
                        ? $this->ensureTaskForConfirmedOrder($product)
                        : $this->createAssignedTask($product, $locked->cod_order_id, (int) $locked->qty);

                    if ($fresh) {
                        $fresh->is_escalated = true;
                        $fresh->escalated_at = now();
                        $fresh->save();
                        $recreated++;
                    }
                }
            });

            Log::warning('Souk: task expired without a claim', [
                'souk_task_id' => $task->id,
                'product_id' => $task->product_id,
            ]);
        }

        // T5: claimed tasks use their own purchase timeout (how long
        // the buyer has to buy after claiming), not the claim timeout
        // (how long an assigned task waits to be claimed).
        $stalled = SoukTask::query()
            ->where('status', SoukTask::STATUS_CLAIMED)
            ->where('is_escalated', false)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', now()->subHours($this->settings->purchaseTimeoutHours()))
            ->orderBy('id')
            ->get();

        foreach ($stalled as $task) {
            DB::transaction(function () use ($task, &$escalated) {
                $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== SoukTask::STATUS_CLAIMED || $locked->is_escalated) {
                    return;
                }

                $locked->is_escalated = true;
                $locked->escalated_at = now();
                $locked->save();
                $escalated++;

                Log::warning('Souk: claimed task stalled without a purchase', [
                    'souk_task_id' => $locked->id,
                    'product_id' => $locked->product_id,
                    'buyer_id' => $locked->buyer_id,
                ]);
            });
        }

        return ['expired' => $expired, 'recreated' => $recreated, 'escalated' => $escalated];
    }

    /**
     * Whether the admin is a souk buyer.
     */
    public function isSoukBuyer(Admin $admin): bool
    {
        return $admin->role?->name === 'Souk Buyer';
    }

    /**
     * Whether the admin is a warehouse controller.
     */
    public function isWarehouseController(Admin $admin): bool
    {
        return $admin->role?->name === 'Warehouse Controller';
    }

    /**
     * Whether the admin may manage the souk panel: verify goods,
     * record payments, cancel tasks. Warehouse controllers, the
     * confirmation supervisor, and admins with full ("all")
     * permissions.
     */
    public function canManageSouk(Admin $admin): bool
    {
        return $this->isWarehouseController($admin)
            || $admin->role?->name === 'Confirmation Supervisor'
            || $admin->role?->permission_type === 'all';
    }

    /**
     * Whether the admin may claim tasks and record purchases: souk
     * buyers, and admins with full ("all") permissions.
     */
    public function canActAsBuyer(Admin $admin): bool
    {
        return $this->isSoukBuyer($admin)
            || $admin->role?->permission_type === 'all';
    }

    /**
     * Live tasks, escalated first, then by deadline.
     */
    public function openTasks(): Collection
    {
        return SoukTask::query()
            ->with(['product', 'buyer', 'codOrder'])
            ->whereIn('status', SoukTask::OPEN_STATUSES)
            ->orderByDesc('is_escalated')
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks of one buyer: live ones first, then their recent
     * history.
     */
    public function tasksForBuyer(Admin $buyer): Collection
    {
        return SoukTask::query()
            ->with(['product', 'codOrder'])
            ->where('buyer_id', $buyer->id)
            ->orderByRaw('FIELD(status, ?, ?, ?, ?, ?) ASC', SoukTask::OPEN_STATUSES)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    /**
     * Trust statistics for one buyer: how many tasks they claimed,
     * delivered (received, verified or paid), were rejected, and
     * were paid — with the delivery and rejection rates.
     */
    public function buyerStats(Admin $buyer): array
    {
        $claimed = SoukTask::query()->where('buyer_id', $buyer->id)->count();

        $delivered = SoukTask::query()
            ->where('buyer_id', $buyer->id)
            ->whereIn('status', [SoukTask::STATUS_RECEIVED, SoukTask::STATUS_VERIFIED, SoukTask::STATUS_PAID])
            ->count();

        $rejected = SoukTask::query()
            ->where('buyer_id', $buyer->id)
            ->where('status', SoukTask::STATUS_REJECTED)
            ->count();

        $paid = SoukTask::query()
            ->where('buyer_id', $buyer->id)
            ->where('status', SoukTask::STATUS_PAID)
            ->count();

        return [
            'admin' => $buyer,
            'claimed' => $claimed,
            'delivered' => $delivered,
            'rejected' => $rejected,
            'paid' => $paid,
            'delivery_rate' => $claimed > 0 ? round($delivered / $claimed * 100) : null,
            'rejection_rate' => $claimed > 0 ? round($rejected / $claimed * 100) : null,
        ];
    }

    /**
     * Trust statistics for every souk buyer (role members plus any
     * admin who ever claimed a task), ordered by delivered count.
     */
    public function allBuyerStats(): array
    {
        $buyerRole = Role::query()->where('name', 'Souk Buyer')->first();

        $buyerIds = SoukTask::query()
            ->whereNotNull('buyer_id')
            ->distinct()
            ->pluck('buyer_id')
            ->all();

        if ($buyerRole) {
            $roleBuyerIds = Admin::query()->where('role_id', $buyerRole->id)->pluck('id')->all();
            $buyerIds = array_values(array_unique(array_merge($buyerIds, $roleBuyerIds)));
        }

        $stats = [];

        foreach ($buyerIds as $buyerId) {
            $buyer = Admin::query()->find($buyerId);

            if ($buyer) {
                $stats[] = $this->buyerStats($buyer);
            }
        }

        usort($stats, fn (array $a, array $b) => $b['delivered'] <=> $a['delivered']);

        return $stats;
    }

    /**
     * Confirmed COD orders that still need souk goods but have no
     * live task yet (creative addition): the souk board shows a
     * banner with this count, so a new confirmed order for an
     * out-of-stock product is noticed before the next 30-minute scan
     * creates its task. Counts distinct orders, not products.
     */
    public function uncoveredConfirmedOrdersCount(): int
    {
        if (! $this->settings->isEnabled()) {
            return 0;
        }

        $storeCount = (int) DB::table('cod_orders as c')
            ->join('order_items as oi', 'oi.order_id', '=', 'c.order_id')
            ->where('c.status', CodOrder::STATUS_CONFIRMED)
            ->whereRaw('(SELECT COALESCE(SUM(qty), 0) FROM product_inventories WHERE product_id = oi.product_id) - (SELECT COALESCE(SUM(qty), 0) FROM product_ordered_inventories WHERE product_id = oi.product_id) <= 0')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('souk_tasks as st')
                    ->whereColumn('st.cod_order_id', 'c.id')
                    ->whereColumn('st.product_id', 'oi.product_id')
                    ->whereIn('st.status', SoukTask::OPEN_STATUSES);
            })
            ->distinct()
            ->count('c.id');

        // N2: WhatsApp/API orders have no store order (order_id is
        // null), so the join above never sees them — a confirmed
        // WhatsApp order with no live task was invisible in the
        // banner. Count them the same way, via the payload items.
        $whatsappCount = 0;

        $whatsappOrders = CodOrder::query()
            ->where('status', CodOrder::STATUS_CONFIRMED)
            ->whereNull('order_id')
            ->get(['id']);

        foreach ($whatsappOrders as $codOrder) {
            foreach ($this->itemsForCodOrder($codOrder) as $item) {
                $product = $item['product'];

                if ($this->stockQty((int) $product->id) > 0) {
                    continue;
                }

                $hasLiveTask = SoukTask::query()
                    ->where('cod_order_id', $codOrder->id)
                    ->where('product_id', (int) $product->id)
                    ->whereIn('status', SoukTask::OPEN_STATUSES)
                    ->exists();

                if (! $hasLiveTask) {
                    $whatsappCount++;
                    break;
                }
            }
        }

        return $storeCount + $whatsappCount;
    }

    /**
     * Tasks linked to one COD order.
     */
    public function tasksForCodOrder(int $codOrderId): Collection
    {
        return SoukTask::query()
            ->with(['product', 'buyer'])
            ->where('cod_order_id', $codOrderId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks whose product is one of the given product ids (used on
     * the store order view, where the link is by product, not by a
     * recorded cod_order_id).
     */
    public function tasksForOrderProductIds(array $productIds): Collection
    {
        if (empty($productIds)) {
            return new Collection;
        }

        return SoukTask::query()
            ->with(['product.attribute_values', 'buyer'])
            ->whereIn('product_id', $productIds)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Effective purchase-price cap for one product: its own cap, or
     * the admin default cap. Null means no cap.
     */
    protected function effectiveCap(int $productId): ?float
    {
        $sourcing = $this->sourcingFor($productId);

        if ($sourcing?->price_cap !== null) {
            return (float) $sourcing->price_cap;
        }

        return $this->settings->defaultPriceCap();
    }

    /**
     * Effective minimum margin for one product: its own minimum, or
     * the admin default. Null means no minimum.
     */
    protected function effectiveMinMargin(int $productId): ?float
    {
        $sourcing = $this->sourcingFor($productId);

        if ($sourcing?->min_margin_percent !== null) {
            return (float) $sourcing->min_margin_percent;
        }

        return $this->settings->defaultMinMarginPercent();
    }

    /**
     * Create a fresh assigned task for a product (used after a
     * rejection or an expiry). Assumes the caller already checked
     * that no live task exists, or holds the lock.
     */
    protected function createAssignedTask(Product $product, ?int $codOrderId, int $qty): ?SoukTask
    {
        if ($this->openTaskForProduct((int) $product->id)) {
            return null;
        }

        return SoukTask::create([
            'product_id' => $product->id,
            'cod_order_id' => $codOrderId,
            'qty' => max(1, $qty),
            'status' => SoukTask::STATUS_ASSIGNED,
            'sale_price' => $this->salePrice((int) $product->id, $codOrderId),
            'deadline_at' => now()->addHours($this->settings->claimTimeoutHours()),
        ]);
    }

    /**
     * The default channel code (M24), from the store configuration
     * instead of the literal string 'default'.
     */
    protected function defaultChannelCode(): string
    {
        try {
            $channel = core()->getDefaultChannel();

            if ($channel && ! empty($channel->code)) {
                return (string) $channel->code;
            }
        } catch (\Throwable) {
        }

        return 'default';
    }

    /**
     * The default channel id (M24), for the inventory index.
     */
    protected function defaultChannelId(): int
    {
        try {
            $channel = core()->getDefaultChannel();

            if ($channel && ! empty($channel->id)) {
                return (int) $channel->id;
            }
        } catch (\Throwable) {
        }

        return 1;
    }

    /**
     * Keep the product inventory index in step with the stock added
     * on receive, when the index table exists. The index is what the
     * storefront reads; without this the received goods would sit in
     * product_inventories while the shop still shows zero.
     */
    protected function refreshInventoryIndex(int $productId): void
    {
        if (! Schema::hasTable('product_inventory_indices')) {
            return;
        }

        $qty = $this->stockQty($productId);

        $indexRows = DB::table('product_inventory_indices')
            ->where('product_id', $productId)
            ->get();

        if ($indexRows->isEmpty()) {
            // M24: the real default channel id, not a hard-coded 1.
            DB::table('product_inventory_indices')->insert([
                'product_id' => $productId,
                'channel_id' => $this->defaultChannelId(),
                'qty' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('product_inventory_indices')
            ->where('product_id', $productId)
            ->update(['qty' => $qty, 'updated_at' => now()]);
    }
}
