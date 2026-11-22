<?php

namespace App\Cod\Souk;

use App\Models\SoukPayment;
use App\Models\SoukProductSourcing;
use App\Models\SoukTask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Inventory\Models\InventorySource;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductFlat;
use Webkul\Product\Models\ProductInventory;
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
     * Sale price of one unit from the default-channel product flat
     * row, or null when the product has no flat row yet.
     */
    public function salePrice(int $productId): ?float
    {
        $flat = ProductFlat::query()
            ->where('product_id', $productId)
            ->where('channel', 'default')
            ->first()
            ?? ProductFlat::query()->where('product_id', $productId)->first();

        return $flat?->price !== null ? (float) $flat->price : null;
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
                    }

                    return $existing;
                }

                return SoukTask::create([
                    'product_id' => $product->id,
                    'cod_order_id' => $codOrderId,
                    'qty' => max(1, $qty),
                    'status' => SoukTask::STATUS_ASSIGNED,
                    'sale_price' => $this->salePrice((int) $product->id),
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
     * Scan every product whose stock is zero (or missing) and make
     * sure it has a live task when its sourcing mode sends it to the
     * souk. Returns ['checked' => n, 'created' => n].
     */
    public function checkZeroStock(): array
    {
        $checked = 0;
        $created = 0;

        $productIds = DB::table('products as p')
            ->leftJoin('souk_product_sourcing as s', 's.product_id', '=', 'p.id')
            ->where(function ($query) {
                $query->whereNull('s.mode')
                    ->orWhereIn('s.mode', [SoukProductSourcing::MODE_AUTO, SoukProductSourcing::MODE_SOUK]);
            })
            ->whereRaw('(SELECT COALESCE(SUM(qty), 0) FROM product_inventories WHERE product_id = p.id) - (SELECT COALESCE(SUM(qty), 0) FROM product_ordered_inventories WHERE product_id = p.id) <= 0')
            ->pluck('p.id');

        foreach ($productIds as $productId) {
            $checked++;
            $product = Product::query()->find($productId);

            if (! $product) {
                continue;
            }

            $hadOpenTask = $this->openTaskForProduct((int) $productId) !== null;

            if ($this->ensureTaskForProduct($product) && ! $hadOpenTask) {
                $created++;
            }
        }

        return ['checked' => $checked, 'created' => $created];
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

        return (bool) DB::transaction(function () use ($task, $buyer, $price, $note) {
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

            $source = InventorySource::query()->where('code', 'default')->first()
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
    public function verify(SoukTask $task, Admin $controller, bool $approve, ?string $reason = null): bool
    {
        if (! $this->canManageSouk($controller)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($task, $controller, $approve, $reason) {
            $locked = SoukTask::query()->where('id', $task->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== SoukTask::STATUS_RECEIVED) {
                return false;
            }

            $locked->controller_id = $controller->id;
            $locked->verified_at = now();

            if ($approve) {
                $locked->status = SoukTask::STATUS_VERIFIED;
                $locked->save();

                return true;
            }

            $locked->status = SoukTask::STATUS_REJECTED;
            $locked->rejection_reason = $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : 'Refused by the warehouse controller (no reason given).';
            $locked->save();

            // The product is needed again: a fresh assigned task so
            // another buyer can take it. The rejected task stays as
            // the history of this buyer's attempt.
            $product = Product::query()->find($locked->product_id);

            if ($product) {
                $this->createAssignedTask($product, $locked->cod_order_id, (int) $locked->qty);
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

        if (! in_array($task->status, [SoukTask::STATUS_ASSIGNED, SoukTask::STATUS_CLAIMED], true)) {
            return false;
        }

        $task->status = SoukTask::STATUS_CANCELED;

        if ($reason !== null && trim($reason) !== '') {
            $task->note = trim($reason);
        }

        $task->save();

        return true;
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
                    $fresh = $this->createAssignedTask($product, $locked->cod_order_id, (int) $locked->qty);

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

        $stalled = SoukTask::query()
            ->where('status', SoukTask::STATUS_CLAIMED)
            ->where('is_escalated', false)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', now()->subHours($this->settings->claimTimeoutHours()))
            ->orderBy('id')
            ->get();

        foreach ($stalled as $task) {
            $task->is_escalated = true;
            $task->escalated_at = now();
            $task->save();
            $escalated++;

            Log::warning('Souk: claimed task stalled without a purchase', [
                'souk_task_id' => $task->id,
                'product_id' => $task->product_id,
                'buyer_id' => $task->buyer_id,
            ]);
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
            ->with(['product', 'buyer'])
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
            'sale_price' => $this->salePrice((int) $product->id),
            'deadline_at' => now()->addHours($this->settings->claimTimeoutHours()),
        ]);
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
            // Default channel is id 1 in a standard Bagisto install.
            DB::table('product_inventory_indices')->insert([
                'product_id' => $productId,
                'channel_id' => 1,
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
