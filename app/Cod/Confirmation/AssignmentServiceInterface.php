<?php

namespace App\Cod\Confirmation;

use App\Models\CodAssignmentRule;
use App\Models\CodOrder;
use Illuminate\Database\Eloquent\Collection;
use Webkul\User\Models\Admin;

/**
 * Distribution of confirmation orders among confirmers (phase 3).
 *
 * Two rule kinds, managed by the confirmation supervisor:
 * - Percentage rules: each confirmer gets a share of new orders
 *   proportional to their weight, distributed with smooth weighted
 *   round-robin so the split stays fair over time.
 * - Product rules: every order containing a given SKU goes to one
 *   confirmer, before percentage rules are considered.
 *
 * The daily limit (Admin → Configuration → General → COD System) is
 * respected: a confirmer who already reached it today receives no
 * further order from either rule kind.
 */
interface AssignmentServiceInterface
{
    /**
     * Every admin with the Confirmer role, ordered by name.
     */
    public function confirmers(): Collection;

    /**
     * Whether the given admin is a confirmer.
     */
    public function isConfirmer(Admin $admin): bool;

    /**
     * Active percentage rules with their confirmer, ordered by weight.
     */
    public function percentageRules(): Collection;

    /**
     * Active product rules with their confirmer, ordered by SKU.
     */
    public function productRules(): Collection;

    /**
     * Create or update the percentage rule of one confirmer.
     */
    public function savePercentageRule(Admin $admin, int $weight): CodAssignmentRule;

    /**
     * Create the product rule of one confirmer for one SKU, or null when
     * that confirmer already has a rule for that SKU.
     */
    public function saveProductRule(Admin $admin, string $productSku): ?CodAssignmentRule;

    /**
     * Delete one assignment rule.
     */
    public function deleteRule(CodAssignmentRule $rule): void;

    /**
     * Number of orders assigned to this confirmer today.
     */
    public function assignedTodayCount(Admin $admin): int;

    /**
     * Whether the confirmer already reached the daily limit today.
     */
    public function reachedDailyLimit(Admin $admin): bool;

    /**
     * Number of pending-confirmation orders with no confirmer yet.
     */
    public function unassignedCount(): int;

    /**
     * Product SKUs of one COD order: from its linked store order items,
     * or from the import payload when there is no linked store order.
     */
    public function orderSkus(CodOrder $order): array;

    /**
     * Assign one pending order to a confirmer: a matching product rule
     * first, otherwise the percentage distribution. Returns the chosen
     * confirmer, or null when no confirmer can take it (no rule, or
     * every eligible confirmer reached the daily limit). Never throws:
     * assignment must never break an import or a checkout.
     */
    public function assign(CodOrder $order): ?Admin;

    /**
     * Assign every unassigned pending order, oldest first. Returns
     * ['assigned' => n, 'skipped' => n] where skipped counts orders no
     * confirmer could take.
     */
    public function assignUnassigned(int $limit = 200): array;

    /**
     * Supervisor override: assign one pending order to a chosen
     * confirmer. Returns false when the admin is not a confirmer or
     * the order is no longer pending confirmation.
     */
    public function manualAssign(CodOrder $order, Admin $admin): bool;
}
