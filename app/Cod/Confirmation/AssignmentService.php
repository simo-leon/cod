<?php

namespace App\Cod\Confirmation;

use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\CodAssignmentRule;
use App\Models\CodOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

class AssignmentService implements AssignmentServiceInterface
{
    public function __construct(
        protected ConfirmationServiceInterface $confirmation,
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

    /**
     * Every admin with the Confirmer role, ordered by name.
     */
    public function confirmers(): Collection
    {
        $role = Role::where('name', 'Confirmer')->first();

        if (! $role) {
            return new Collection;
        }

        return Admin::where('role_id', $role->id)->orderBy('name')->get();
    }

    /**
     * Whether the given admin is a confirmer.
     */
    public function isConfirmer(Admin $admin): bool
    {
        return $admin->role?->name === 'Confirmer';
    }

    /**
     * Active percentage rules with their confirmer, ordered by weight.
     */
    public function percentageRules(): Collection
    {
        return CodAssignmentRule::query()
            ->with('confirmer')
            ->where('type', CodAssignmentRule::TYPE_PERCENTAGE)
            ->where('is_active', true)
            ->orderByDesc('weight')
            ->orderBy('admin_id')
            ->get();
    }

    /**
     * Active product rules with their confirmer, ordered by SKU.
     */
    public function productRules(): Collection
    {
        return CodAssignmentRule::query()
            ->with('confirmer')
            ->where('type', CodAssignmentRule::TYPE_PRODUCT)
            ->where('is_active', true)
            ->orderBy('product_sku')
            ->get();
    }

    /**
     * Create or update the percentage rule of one confirmer.
     */
    public function savePercentageRule(Admin $admin, int $weight): CodAssignmentRule
    {
        $weight = max(0, $weight);

        $rule = CodAssignmentRule::firstOrNew([
            'type' => CodAssignmentRule::TYPE_PERCENTAGE,
            'admin_id' => $admin->id,
            'product_sku' => null,
        ]);

        // A changed weight restarts the round-robin state, otherwise the
        // old drift would keep favouring (or starving) this confirmer.
        if ($rule->exists && (int) $rule->weight !== $weight) {
            $rule->current_weight = 0;
        }

        $rule->weight = $weight;
        $rule->is_active = true;
        $rule->save();

        return $rule;
    }

    /**
     * Create the product rule of one confirmer for one SKU, or null when
     * that confirmer already has a rule for that SKU.
     */
    public function saveProductRule(Admin $admin, string $productSku): ?CodAssignmentRule
    {
        $productSku = trim($productSku);

        if ($productSku === '') {
            return null;
        }

        $existing = CodAssignmentRule::query()
            ->where('type', CodAssignmentRule::TYPE_PRODUCT)
            ->where('admin_id', $admin->id)
            ->where('product_sku', $productSku)
            ->first();

        if ($existing) {
            return null;
        }

        return CodAssignmentRule::create([
            'type' => CodAssignmentRule::TYPE_PRODUCT,
            'admin_id' => $admin->id,
            'product_sku' => $productSku,
            'weight' => 0,
            'current_weight' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Delete one assignment rule.
     */
    public function deleteRule(CodAssignmentRule $rule): void
    {
        $rule->delete();
    }

    /**
     * Number of orders assigned to this confirmer today.
     */
    public function assignedTodayCount(Admin $admin): int
    {
        return CodOrder::query()
            ->where('assigned_to', $admin->id)
            ->whereDate('assigned_at', today())
            ->count();
    }

    /**
     * Whether the confirmer already reached the daily limit today.
     */
    public function reachedDailyLimit(Admin $admin): bool
    {
        return $this->assignedTodayCount($admin) >= $this->confirmation->confirmerDailyLimit();
    }

    /**
     * Number of pending-confirmation orders with no confirmer yet.
     */
    public function unassignedCount(): int
    {
        return CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNull('assigned_to')
            ->count();
    }

    /**
     * Product SKUs of one COD order: from its linked store order items,
     * or from the import payload when there is no linked store order.
     */
    public function orderSkus(CodOrder $order): array
    {
        $skus = [];

        if ($order->order_id) {
            $skus = $order->order?->items()->pluck('sku')->all() ?? [];
        }

        if (empty($skus) && is_array($order->payload)) {
            foreach ($order->payload['items'] ?? [] as $item) {
                if (is_array($item) && ! empty($item['sku'])) {
                    $skus[] = (string) $item['sku'];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $skus))));
    }

    /**
     * Assign one pending order to a confirmer: a matching product rule
     * first, otherwise the percentage distribution. Returns the chosen
     * confirmer, or null when no confirmer can take it (no rule, or
     * every eligible confirmer reached the daily limit). Never throws:
     * assignment must never break an import or a checkout.
     */
    public function assign(CodOrder $order): ?Admin
    {
        if ($order->status !== CodOrder::STATUS_PENDING_CONFIRMATION || $order->assigned_to) {
            return $order->assignedTo;
        }

        try {
            $confirmer = DB::transaction(function () use ($order) {
                $confirmer = $this->pickByProductRule($order) ?? $this->pickByPercentage();

                if (! $confirmer) {
                    return null;
                }

                $order->assigned_to = $confirmer->id;
                $order->assigned_at = now();
                $order->save();

                return $confirmer;
            });

            // Phase 4: the customer gets the WhatsApp confirmation
            // message as soon as a confirmer takes the order. Sending
            // never throws and never re-sends (confirmation_sent_at).
            if ($confirmer) {
                $this->whatsapp->sendConfirmation($order);
            }

            return $confirmer;
        } catch (\Throwable $e) {
            Log::warning('COD: failed to assign order', [
                'cod_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Assign every unassigned pending order, oldest first. Returns
     * ['assigned' => n, 'skipped' => n] where skipped counts orders no
     * confirmer could take.
     */
    public function assignUnassigned(int $limit = 200): array
    {
        $assigned = 0;
        $skipped = 0;

        $orders = CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNull('assigned_to')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($orders as $order) {
            if ($this->assign($order)) {
                $assigned++;
            } else {
                $skipped++;
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Supervisor override: assign one pending order to a chosen
     * confirmer. Returns false when the admin is not a confirmer or
     * the order is no longer pending confirmation.
     */
    public function manualAssign(CodOrder $order, Admin $admin): bool
    {
        if (! $this->isConfirmer($admin)) {
            return false;
        }

        if ($order->status !== CodOrder::STATUS_PENDING_CONFIRMATION) {
            return false;
        }

        // M9: a manual assignment may overwrite an existing assignee
        // and may exceed the daily limit (a deliberate supervisor
        // decision) — but both must leave a trace, so a confirmer is
        // never silently dropped from an order.
        $previousAssigneeId = $order->assigned_to;
        $overLimit = $this->reachedDailyLimit($admin);

        if ($previousAssigneeId !== null && $previousAssigneeId !== $admin->id) {
            Log::warning('COD: manual assignment overwrote an existing assignee', [
                'cod_order_id' => $order->id,
                'previous_assignee_id' => $previousAssigneeId,
                'new_assignee_id' => $admin->id,
            ]);
        }

        if ($overLimit) {
            Log::warning('COD: manual assignment exceeded the confirmer daily limit', [
                'cod_order_id' => $order->id,
                'assignee_id' => $admin->id,
                'assigned_today' => $this->assignedTodayCount($admin),
                'daily_limit' => $this->confirmation->confirmerDailyLimit(),
            ]);
        }

        $order->assigned_to = $admin->id;
        $order->assigned_at = now();
        $order->save();

        // Phase 4: same confirmation message as automatic assignment.
        $this->whatsapp->sendConfirmation($order);

        return true;
    }

    /**
     * Product rule match: the first active rule whose SKU is in the
     * order and whose confirmer is still under the daily limit.
     */
    protected function pickByProductRule(CodOrder $order): ?Admin
    {
        $skus = $this->orderSkus($order);

        if (empty($skus)) {
            return null;
        }

        // M10: lock the rule rows inside the assignment transaction
        // (pickByPercentage already locks its rows) — two concurrent
        // assignments for the same product rule can no longer both
        // pass the daily-limit check.
        $rules = CodAssignmentRule::query()
            ->with('confirmer')
            ->where('type', CodAssignmentRule::TYPE_PRODUCT)
            ->where('is_active', true)
            ->whereIn('product_sku', $skus)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $confirmer = $rule->confirmer;

            if ($confirmer && ! $this->reachedDailyLimit($confirmer)) {
                return $confirmer;
            }
        }

        return null;
    }

    /**
     * Smooth weighted round-robin (same idea as nginx): every eligible
     * rule gains its weight, the highest total wins, and the total
     * weight is subtracted from the winner — so with weights 50/30/20
     * the split converges on 50% / 30% / 20% instead of drifting.
     * Confirmers at the daily limit are excluded from the round.
     */
    protected function pickByPercentage(): ?Admin
    {
        $rules = CodAssignmentRule::query()
            ->with('confirmer')
            ->where('type', CodAssignmentRule::TYPE_PERCENTAGE)
            ->where('is_active', true)
            ->where('weight', '>', 0)
            ->lockForUpdate()
            ->orderByDesc('weight')
            ->orderBy('admin_id')
            ->get()
            ->filter(fn (CodAssignmentRule $rule) => $rule->confirmer && ! $this->reachedDailyLimit($rule->confirmer));

        if ($rules->isEmpty()) {
            return null;
        }

        $totalWeight = $rules->sum('weight');
        $winner = null;

        foreach ($rules as $rule) {
            $rule->current_weight += $rule->weight;

            if (! $winner || $rule->current_weight > $winner->current_weight) {
                $winner = $rule;
            }
        }

        foreach ($rules as $rule) {
            if ($rule->id === $winner->id) {
                $rule->current_weight -= $totalWeight;
            }

            $rule->save();
        }

        return $winner->confirmer;
    }
}
