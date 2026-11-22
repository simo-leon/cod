<?php

namespace App\Listeners;

use App\Cod\Automation\AutomationSettings;
use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Support\Facades\Log;
use Webkul\Sales\Models\Order;

/**
 * Sends a cash-on-delivery order to the configured delivery provider
 * right after the order is placed. A delivery failure is recorded on
 * the parcel record and never breaks checkout.
 *
 * With the WhatsApp automation on and "send the parcel automatically
 * on confirmation" off, the parcel is NOT sent here: it is sent when
 * the order is confirmed (on WhatsApp, or by the team), so an
 * unconfirmed order never reaches the delivery company. Otherwise
 * the behaviour is unchanged: the parcel is sent at checkout.
 */
class SendOrderToDeliveryProvider
{
    public function __construct(protected AutomationSettings $automation) {}

    public function handle(Order $order): void
    {
        if ($this->automation->isEnabled() && ! $this->automation->autoSendToDelivery()) {
            Log::info('Delivery: parcel not sent at checkout, automation waits for confirmation', [
                'order_id' => $order->id ?? null,
            ]);

            return;
        }

        try {
            app(DeliveryProviderManager::class)->sendOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Delivery: listener failed to send order to provider', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
