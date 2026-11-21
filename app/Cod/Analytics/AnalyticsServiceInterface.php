<?php

namespace App\Cod\Analytics;

/**
 * Figures for the COD dashboard.
 *
 * Phase 1 only counts what already exists in the database: orders and
 * delivery parcels. Rates, confirmer performance and delivery reports
 * arrive with the Analytics module in a later phase.
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
}
