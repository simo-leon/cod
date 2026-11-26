<?php

namespace App\Cod\Inventory;

use App\Models\CodOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Sales\Models\Order;

/**
 * Physical stock for COD orders.
 *
 * Jemla sells over stock: a customer may order more than the
 * on-hand quantity, and no stock check blocks the checkout. The
 * physical stock is deducted only when the COD order is confirmed,
 * so stock 10 + an order of 20 becomes -10 at confirmation — and
 * goes back to 10 when the order is refused or cancelled.
 *
 * Why the deduction also releases the reservation
 * (product_ordered_inventories): the available quantity is
 * on-hand minus reserved, both for the souk panel
 * (SoukService::stockQty) and for the product inventory index.
 * Deducting the physical stock without releasing the reservation
 * would count the same units twice and send covered orders to the
 * souk. Deduct + release keeps the available quantity exactly
 * unchanged, so the souk gate behaves as before.
 *
 * Why shipments and invoices skip the decrement afterwards: the
 * stock was already taken at confirmation. Without the skip, the
 * shipment would take it a second time (and its floor at zero
 * would silently erase the negative balance).
 */
class CodStockService implements CodStockServiceInterface
{
    public function isDeductedForOrder(int $orderId): bool
    {
        return CodOrder::query()
            ->where('order_id', $orderId)
            ->where('stock_deducted', true)
            ->exists();
    }

    public function deductForConfirmedOrder(CodOrder $codOrder): void
    {
        $storeOrder = $codOrder->order_id ? $codOrder->order : null;

        if (! $storeOrder) {
            // WhatsApp / API orders have no store order behind them:
            // there is no store stock to deduct.
            Log::info('COD stock: no store order, nothing to deduct', [
                'cod_order_id' => $codOrder->id,
            ]);

            return;
        }

        DB::transaction(function () use ($codOrder, $storeOrder) {
            $locked = CodOrder::query()->where('id', $codOrder->id)->lockForUpdate()->first();

            if (! $locked || $locked->stock_deducted) {
                return;
            }

            $deductions = [];

            foreach ($this->stockableItems($storeOrder) as $item) {
                $product = $item['product'];
                $qty = $item['qty'];

                $deducted = $this->deductProductQty($product, (int) $storeOrder->channel_id, $qty);

                if ($deducted > 0) {
                    $deductions[] = ['product_id' => (int) $product->id, 'qty' => $deducted];

                    Event::dispatch('catalog.product.update.after', $product);
                }
            }

            // A direct query update: saving the model here would fire
            // eloquent.updated again and re-enter this listener.
            // (A query update applies no casts, so the JSON column
            // is encoded by hand.)
            CodOrder::query()->where('id', $locked->id)->update([
                'stock_deducted' => true,
                'stock_deductions' => $deductions === [] ? null : json_encode($deductions),
            ]);

            Log::info('COD stock: deducted at confirmation', [
                'cod_order_id' => $locked->id,
                'order_id' => $storeOrder->id,
                'deductions' => $deductions,
            ]);
        });
    }

    public function restoreForCanceledOrder(CodOrder $codOrder): void
    {
        DB::transaction(function () use ($codOrder) {
            $locked = CodOrder::query()->where('id', $codOrder->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->stock_deducted) {
                return;
            }

            $storeOrder = $locked->order_id ? $locked->order : null;

            if (! $storeOrder) {
                CodOrder::query()->where('id', $locked->id)->update([
                    'stock_deducted' => false,
                    'stock_deductions' => null,
                ]);

                return;
            }

            $shippedByProduct = $this->shippedQtyByProduct($storeOrder);

            foreach (is_array($locked->stock_deductions) ? $locked->stock_deductions : [] as $deduction) {
                $productId = (int) ($deduction['product_id'] ?? 0);
                $deducted = (int) ($deduction['qty'] ?? 0);

                if ($productId <= 0 || $deducted <= 0) {
                    continue;
                }

                // Units already shipped were returned to the stock by
                // the existing cancel path
                // (OrderItemRepository::returnQtyToProductInventory),
                // so only the never-shipped part is restored here.
                $restore = max(0, $deducted - (int) ($shippedByProduct[$productId] ?? 0));

                if ($restore <= 0) {
                    continue;
                }

                $product = Product::query()->find($productId);

                if (! $product) {
                    continue;
                }

                $inventory = $product->inventories()
                    ->whereIn('inventory_source_id', $this->channelSourceIds((int) $storeOrder->channel_id))
                    ->orderBy('id')
                    ->first();

                if (! $inventory) {
                    Log::warning('COD stock: no inventory row to restore into', [
                        'cod_order_id' => $locked->id,
                        'product_id' => $productId,
                    ]);

                    continue;
                }

                $inventory->update(['qty' => (int) $inventory->qty + $restore]);

                Event::dispatch('catalog.product.update.after', $product);
            }

            CodOrder::query()->where('id', $locked->id)->update([
                'stock_deducted' => false,
                'stock_deductions' => null,
            ]);

            Log::info('COD stock: restored after cancel', [
                'cod_order_id' => $locked->id,
                'order_id' => $storeOrder->id,
            ]);
        });
    }

    public function restoreForStoreOrder(Order $order): void
    {
        $codOrder = CodOrder::query()->where('order_id', $order->id)->first();

        if (! $codOrder) {
            return;
        }

        $this->restoreForCanceledOrder($codOrder);
    }

    /**
     * Order items that move physical stock: composite parents are
     * flattened to their children, products without stock
     * management are skipped.
     *
     * @return array<int, array{product: Product, qty: int}>
     */
    protected function stockableItems(Order $order): array
    {
        $items = [];

        foreach ($order->items as $item) {
            $rows = $item->getTypeInstance()->isComposite() ? $item->children : [$item];

            foreach ($rows as $row) {
                if (! $row->product_id || ! $row->product) {
                    continue;
                }

                if (! $row->product->manage_stock) {
                    continue;
                }

                $items[] = [
                    'product' => $row->product,
                    'qty' => max(1, (int) ($row->qty_ordered ?? 1)),
                ];
            }
        }

        return $items;
    }

    /**
     * Shipped quantities per product of one order, for the restore
     * math (composite parents flattened to their children).
     *
     * @return array<int, int>
     */
    protected function shippedQtyByProduct(Order $order): array
    {
        $shipped = [];

        foreach ($order->items as $item) {
            $rows = $item->getTypeInstance()->isComposite() ? $item->children : [$item];

            foreach ($rows as $row) {
                if (! $row->product_id) {
                    continue;
                }

                $shipped[(int) $row->product_id] = ((int) ($shipped[(int) $row->product_id] ?? 0))
                    + (int) ($row->qty_shipped ?? 0);
            }
        }

        return $shipped;
    }

    /**
     * Take $qty out of the product's physical stock, draining the
     * channel's inventory sources from fullest to emptiest. The
     * last source absorbs the remainder even when it goes
     * negative — overselling is allowed. Returns the quantity
     * actually taken (0 when the product has no inventory row).
     * The ordered reservation is released by the same amount, so
     * the available quantity (on-hand minus reserved) is unchanged.
     */
    protected function deductProductQty(Product $product, int $channelId, int $qty): int
    {
        $sourceIds = $this->channelSourceIds($channelId);

        $inventories = $product->inventories()
            ->whereIn('inventory_source_id', $sourceIds)
            ->orderBy('qty', 'desc')
            ->get();

        if ($inventories->isEmpty()) {
            Log::warning('COD stock: no inventory row to deduct from', [
                'product_id' => $product->id,
                'qty' => $qty,
            ]);

            return 0;
        }

        $remaining = $qty;
        $count = $inventories->count();

        foreach ($inventories as $index => $inventory) {
            if ($remaining <= 0) {
                break;
            }

            $isLast = $index === $count - 1;

            // The last source takes the whole remainder, even past
            // zero; the others only give what they have.
            $take = $isLast ? $remaining : min($remaining, max(0, (int) $inventory->qty));

            if ($take <= 0) {
                continue;
            }

            $inventory->update(['qty' => (int) $inventory->qty - $take]);

            $remaining -= $take;
        }

        $deducted = $qty - $remaining;

        if ($deducted > 0) {
            $ordered = $product->ordered_inventories()->where('channel_id', $channelId)->first();

            if ($ordered) {
                $ordered->update(['qty' => max(0, (int) $ordered->qty - $deducted)]);
            }
        }

        return $deducted;
    }

    /**
     * Inventory source ids of one channel.
     *
     * @return array<int, int>
     */
    protected function channelSourceIds(int $channelId): array
    {
        return DB::table('channel_inventory_sources')
            ->where('channel_id', $channelId)
            ->pluck('inventory_source_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
