<?php

namespace App\Listeners;

use App\Cod\Souk\SoukServiceInterface;
use App\Models\CodOrder;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Sales\Models\Order;

/**
 * Automatic zero-stock assignment to the souk panel.
 *
 * - When a product is updated in the admin (stock edited), check
 *   whether it just hit zero.
 * - When a store order is placed, check every ordered product: the
 *   order reserves stock (product_ordered_inventories), so the
 *   available quantity can hit zero right here.
 * - When a COD order becomes confirmed, check its items too: an
 *   order waits for confirmation first, so the checkout listener
 *   below creates nothing — the task is created here instead, once
 *   the order is confirmed AND the product is out of stock.
 *
 * The service never throws and does nothing when the panel is
 * switched off, so these listeners can never break a checkout, a
 * product save, or a confirmation.
 */
class AssignZeroStockToSouk
{
    /**
     * A product was updated in the admin: it may have just hit zero.
     * Only a confirmed COD order waiting for this product creates a
     * task — an admin stock edit alone never sends a product to the
     * panel, exactly like the 30-minute scan.
     */
    public function handleProductUpdate(Product $product): void
    {
        try {
            app(SoukServiceInterface::class)->ensureTaskForConfirmedOrder($product);
        } catch (\Throwable $e) {
            Log::warning('Souk: product-update listener failed', [
                'product_id' => $product->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A store order was placed: every ordered product may have just
     * hit zero available stock. But the order is not confirmed yet —
     * it waits for confirmation first — so no task is created here.
     * The task is created when the COD order becomes confirmed
     * (handleCodOrderConfirmed), and only then.
     */
    public function handleOrder(Order $order): void
    {
        try {
            $codOrder = CodOrder::query()->where('order_id', $order->id)->first();

            // The confirmation gate: an unconfirmed order must never
            // reach the souk panel, and an order that was never
            // imported into the COD System has nothing confirmed
            // either — it is skipped the same way.
            if (! $codOrder || $codOrder->status !== CodOrder::STATUS_CONFIRMED) {
                return;
            }

            $souk = app(SoukServiceInterface::class);

            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $souk->ensureTaskForProduct($product, $codOrder->id, (int) ($item->qty_ordered ?? 1));
            }
        } catch (\Throwable $e) {
            Log::warning('Souk: order listener failed', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A COD order became confirmed: its out-of-stock items go to the
     * souk panel now. Runs on the created event (an already-confirmed
     * store order imported for the first time) and on the updated
     * event (a pending order confirmed by call, by WhatsApp, or by a
     * store status sync) — but only on the transition into confirmed,
     * never on later updates of an already-confirmed order. Orders
     * without a linked store order (WhatsApp product inquiries) have
     * no items to check. Never throws: a failure here must not break
     * the confirmation itself.
     */
    public function handleCodOrderConfirmed(CodOrder $codOrder): void
    {
        try {
            if ($codOrder->status !== CodOrder::STATUS_CONFIRMED) {
                return;
            }

            // Only the transition into confirmed: a freshly created
            // confirmed record runs, and an update runs only when the
            // status itself just changed. Later updates of an
            // already-confirmed order (assignment, notes, ...) do
            // nothing here.
            if (! $codOrder->wasRecentlyCreated && ! $codOrder->wasChanged('status')) {
                return;
            }

            $order = $codOrder->order_id
                ? Order::query()->find($codOrder->order_id)
                : null;

            if (! $order) {
                return;
            }

            $souk = app(SoukServiceInterface::class);

            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $souk->ensureTaskForProduct($product, $codOrder->id, (int) ($item->qty_ordered ?? 1));
            }
        } catch (\Throwable $e) {
            Log::warning('Souk: confirmed-order listener failed', [
                'cod_order_id' => $codOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
