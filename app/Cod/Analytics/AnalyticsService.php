<?php

namespace App\Cod\Analytics;

use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Orders\OrderServiceInterface;

class AnalyticsService implements AnalyticsServiceInterface
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected DeliveryServiceInterface $delivery,
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
}
