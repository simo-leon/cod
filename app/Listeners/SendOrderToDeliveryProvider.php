<?php

namespace App\Listeners;

use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Support\Facades\Log;
use Webkul\Sales\Models\Order;

/**
 * Sends a cash-on-delivery order to the configured delivery provider
 * right after the order is placed. A delivery failure is recorded on
 * the parcel record and never breaks checkout.
 */
class SendOrderToDeliveryProvider
{
    public function handle(Order $order): void
    {
        try {
            app(DeliveryProviderManager::class)->sendOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Delivery: listener failed to send order to provider', [
                'order_id' => $order->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
