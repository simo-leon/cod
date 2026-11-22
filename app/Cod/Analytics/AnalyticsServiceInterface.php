<?php

namespace App\Cod\Analytics;

use Webkul\User\Models\Admin;

/**
 * Figures for the COD dashboard.
 *
 * Phase 1 only counts what already exists in the database: orders and
 * delivery parcels. Phase 3 adds one rate per confirmer: the
 * confirmation rate (confirmed / assigned) and the delivery rate
 * (delivered / confirmed). Phase 7 adds the full analytics page:
 * every figure comes from real COD data or from admin settings, and
 * anything without data is returned as null — never invented.
 */
interface AnalyticsServiceInterface
{
    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int;

    /**
     * Number of parcels recorded in delivery_parcels, or null when the
     * table does not exist yet.
     */
    public function parcelsCount(): ?int;

    /**
     * Configured name of the COD System (Admin → Configuration → General
     * → COD System), default "COD System".
     */
    public function systemName(): string;

    /**
     * Confirmation and delivery rates of one confirmer:
     * ['admin', 'assigned', 'confirmed', 'delivered',
     * 'confirmation_rate', 'delivery_rate']. A rate is null when its
     * denominator is zero (no assigned orders, or no confirmed order).
     */
    public function confirmerRate(Admin $admin): array;

    /**
     * The same rates for every confirmer, ordered by name.
     */
    public function confirmerRates(): array;

    /**
     * Commission (DH) paid to a confirmer per delivered order, from
     * the COD System analytics settings — or null when it was never
     * entered in the admin.
     */
    public function commissionPerDeliveredOrder(): ?float;

    /**
     * Default delivery cost (DH) per order sent to delivery, from the
     * COD System analytics settings — or null when it was never
     * entered in the admin.
     */
    public function defaultDeliveryCost(): ?float;

    /**
     * Default product cost (DH) per unit, from the COD System
     * analytics settings — or null when it was never entered.
     */
    public function defaultProductCost(): ?float;

    /**
     * Per-SKU product costs (DH per unit) from the COD System
     * analytics settings: ['SKU' => cost]. Empty when none entered.
     */
    public function productCosts(): array;

    /**
     * Cost (DH) of one unit of a SKU: the per-SKU cost when set,
     * otherwise the default product cost, otherwise null.
     */
    public function productCost(string $sku): ?float;

    /**
     * Full analytics for the COD analytics page, filtered by
     * ['from' => 'Y-m-d'|null, 'to' => 'Y-m-d'|null, 'city' => ?string,
     * 'provider' => ?string, 'sku' => ?string, 'assigned_to' => ?int,
     * 'product_sort' => 'most-ordered'|'least-ordered'].
     * Every figure comes from real COD data or from admin settings; a
     * figure without data is null and the 'missing' lists say what
     * must be entered. `assigned_to` scopes every figure — and the
     * filter options and the team table — to one confirmer's orders.
     */
    public function analytics(array $filters): array;
}
