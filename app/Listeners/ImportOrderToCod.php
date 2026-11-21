<?php

namespace App\Listeners;

use App\Cod\Orders\OrderServiceInterface;
use Illuminate\Support\Facades\Log;
use Webkul\Sales\Models\Order;

/**
 * Records a cash-on-delivery order in the COD System right after it is
 * placed. Only COD orders are imported; anything else is ignored. An
 * import failure is logged and never breaks checkout.
 */
class ImportOrderToCod
{
    public function handle(Order $order): void
    {
        try {
            app(OrderServiceInterface::class)->importOrder($order);
        } catch (\Throwable $e) {
            Log::warning('COD: webhook listener failed to import order', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
