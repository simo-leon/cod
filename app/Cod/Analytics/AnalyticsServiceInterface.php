<?php

namespace App\Cod\Analytics;

use Webkul\User\Models\Admin;

/**
 * Figures for the COD dashboard.
 *
 * Phase 1 only counts what already exists in the database: orders and
 * delivery parcels. Phase 3 adds one rate per confirmer: the
 * confirmation rate (confirmed / assigned) and the delivery rate
 * (delivered / confirmed).
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
}
