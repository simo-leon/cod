<?php

namespace App\Listeners;

use App\Cod\Inventory\CodStockServiceInterface;
use App\Models\CodOrder;
use Illuminate\Support\Facades\Log;
use Webkul\Sales\Models\Order;

/**
 * Physical stock follows the COD confirmation, not the checkout.
 *
 * - A COD order becomes confirmed (by call, by WhatsApp, or by a
 *   store status sync): deduct the ordered quantities — the stock
 *   may go negative, overselling is allowed.
 * - A COD order is refused or cancelled: put the deducted stock
 *   back.
 * - The store order itself is cancelled or returned from the
 *   admin: put the deducted stock back too (the COD record may
 *   stay confirmed, so the COD listener above would never fire).
 *
 * The service is idempotent (one deduction per COD order, guarded
 * by cod_orders.stock_deducted), and this listener never throws:
 * a stock failure must never break a confirmation or a cancel.
 */
class AdjustStockOnCodConfirmation
{
    /**
     * A COD order changed: deduct on the transition into
     * confirmed, restore on the transition into canceled. Runs on
     * the created event (an already-confirmed store order imported
     * for the first time) and on the updated event — but only when
     * the status itself just changed, never on later updates of an
     * already-confirmed order (assignment, notes, ...).
     */
    public function handleCodOrder(CodOrder $codOrder): void
    {
        try {
            $stock = app(CodStockServiceInterface::class);

            if (
                $codOrder->status === CodOrder::STATUS_CONFIRMED
                && ($codOrder->wasRecentlyCreated || $codOrder->wasChanged('status'))
            ) {
                $stock->deductForConfirmedOrder($codOrder);
            } elseif (
                $codOrder->status === CodOrder::STATUS_CANCELED
                && $codOrder->wasChanged('status')
            ) {
                $stock->restoreForCanceledOrder($codOrder);
            }
        } catch (\Throwable $e) {
            Log::warning('COD stock: failed to adjust stock on COD status change', [
                'cod_order_id' => $codOrder->id ?? null,
                'status' => $codOrder->status ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A store order changed status: only a cancel or a return puts
     * the confirmation deduction back. Every other status change
     * (confirmed, out for delivery, delivered, paid, ...) is a
     * no-op here.
     */
    public function handleStoreOrderStatus(Order $order, $newStatus = null, $previousStatus = null): void
    {
        try {
            $status = $newStatus ?? $order->status;

            if (! in_array($status, [Order::STATUS_CANCELED, Order::STATUS_RETURNED], true)) {
                return;
            }

            app(CodStockServiceInterface::class)->restoreForStoreOrder($order);
        } catch (\Throwable $e) {
            Log::warning('COD stock: failed to restore stock on store order status change', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A store order was cancelled through OrderRepository::cancel
     * (admin cancel button, customer cancel). The update-status
     * listener above already restored the stock on the same cancel
     * — the service guard makes this second call a no-op.
     */
    public function handleStoreOrderCancel(Order $order): void
    {
        try {
            app(CodStockServiceInterface::class)->restoreForStoreOrder($order);
        } catch (\Throwable $e) {
            Log::warning('COD stock: failed to restore stock on store order cancel', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
