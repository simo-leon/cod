<?php

namespace App\Cod\Delivery;

use App\Models\DeliveryParcel;
use Webkul\Sales\Models\Order;

/**
 * Delivery figures and actions for the COD System.
 *
 * This module does not re-implement delivery: it reuses the existing
 * DeliveryProviderInterface + DeliveryProviderManager in
 * app/Services/Delivery/ (Vitis Express today, more companies later).
 * Adding a company still means only a new provider class, a config
 * section and one registry line in that manager.
 */
interface DeliveryServiceInterface
{
    /**
     * Whether the default delivery provider is enabled and has credentials.
     */
    public function isConfigured(): bool;

    /**
     * Human readable label of the default delivery provider, or null.
     */
    public function defaultProviderLabel(): ?string;

    /**
     * Whether the delivery_parcels table exists (it is created by its
     * migration; the admin page must not crash when it is missing).
     */
    public function parcelsTableExists(): bool;

    /**
     * Number of parcels recorded in delivery_parcels, or null when the
     * table does not exist yet.
     */
    public function parcelsCount(): ?int;

    /**
     * Send one order to the default delivery provider. Delegates to
     * DeliveryProviderManager and never throws: see that manager.
     */
    public function sendOrder(Order $order, bool $force = false): ?DeliveryParcel;
}
