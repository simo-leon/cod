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
 *
 * The service never throws and does nothing when the panel is
 * switched off, so these listeners can never break a checkout or a
 * product save.
 */
class AssignZeroStockToSouk
{
    /**
     * A product was updated in the admin: it may have just hit zero.
     */
    public function handleProductUpdate(Product $product): void
    {
        try {
            app(SoukServiceInterface::class)->ensureTaskForProduct($product);
        } catch (\Throwable $e) {
            Log::warning('Souk: product-update listener failed', [
                'product_id' => $product->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A store order was placed: every ordered product may have just
     * hit zero available stock. The task is linked to the COD order
     * when the order was already imported (the import listener runs
     * on the same event); otherwise the link is left empty and the
     * task is still created.
     */
    public function handleOrder(Order $order): void
    {
        try {
            $souk = app(SoukServiceInterface::class);

            $codOrder = CodOrder::query()->where('order_id', $order->id)->first();

            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $souk->ensureTaskForProduct($product, $codOrder?->id, (int) ($item->qty_ordered ?? 1));
            }
        } catch (\Throwable $e) {
            Log::warning('Souk: order listener failed', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
