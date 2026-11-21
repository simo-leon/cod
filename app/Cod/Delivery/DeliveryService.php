<?php

namespace App\Cod\Delivery;

use App\Models\DeliveryParcel;
use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;

class DeliveryService implements DeliveryServiceInterface
{
    public function __construct(protected DeliveryProviderManager $manager) {}

    /**
     * Whether the default delivery provider is enabled and has credentials.
     */
    public function isConfigured(): bool
    {
        return $this->manager->isConfigured();
    }

    /**
     * Human readable label of the default delivery provider, or null.
     */
    public function defaultProviderLabel(): ?string
    {
        return $this->manager->defaultProvider()?->getLabel();
    }

    /**
     * Whether the delivery_parcels table exists (it is created by its
     * migration; the admin page must not crash when it is missing).
     */
    public function parcelsTableExists(): bool
    {
        return Schema::hasTable('delivery_parcels');
    }

    /**
     * Number of parcels recorded in delivery_parcels, or null when the
     * table does not exist yet.
     */
    public function parcelsCount(): ?int
    {
        if (! $this->parcelsTableExists()) {
            return null;
        }

        return DeliveryParcel::count();
    }

    /**
     * Send one order to the default delivery provider. Delegates to
     * DeliveryProviderManager and never throws: see that manager.
     */
    public function sendOrder(Order $order, bool $force = false): ?DeliveryParcel
    {
        return $this->manager->sendOrder($order, $force);
    }
}
