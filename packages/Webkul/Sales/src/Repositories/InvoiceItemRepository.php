<?php

namespace Webkul\Sales\Repositories;

use App\Cod\Inventory\CodStockService;
use App\Cod\Inventory\CodStockServiceInterface;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Eloquent\Repository;

class InvoiceItemRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model(): string
    {
        return 'Webkul\Sales\Contracts\InvoiceItem';
    }

    /**
     * @param  array  $data
     * @return void
     */
    public function updateProductInventory($data)
    {
        if (! $data['product']) {
            return;
        }

        if (! $data['product']->manage_stock) {
            return;
        }

        $orderedInventory = $data['product']->ordered_inventories()
            ->where('channel_id', $data['invoice']->order->channel->id)
            ->first();

        if ($orderedInventory) {
            if (($orderedQty = $orderedInventory->qty - $data['qty']) < 0) {
                $orderedQty = 0;
            }

            $orderedInventory->update(['qty' => $orderedQty]);
        }

        $inventories = $data['product']->inventories()
            ->where('vendor_id', $data['vendor_id'])
            ->whereIn('inventory_source_id', $data['invoice']->order->channel->inventory_sources()->pluck('id'))
            ->orderBy('qty', 'desc')
            ->get();

        /*
         * Jemla: the physical stock was already deducted when the
         * COD order was confirmed
         * (App\Cod\Inventory\CodStockService), so the invoice must
         * not take it a second time. The ordered reservation above
         * was still reduced.
         */
        if ($this->isStockAlreadyDeducted($data['invoice']->order)) {
            Event::dispatch('catalog.product.update.after', $data['product']);

            return;
        }

        foreach ($inventories as $inventory) {
            if ($inventory->qty >= $data['qty']) {
                $inventory->update(['qty' => $inventory->qty - $data['qty']]);

                break;
            } else {
                $data['qty'] -= $inventory->qty;

                $inventory->update(['qty' => 0]);
            }
        }

        Event::dispatch('catalog.product.update.after', $data['product']);
    }

    /**
     * Whether the physical stock of this order was already deducted
     * at COD confirmation. Defensive on purpose: when the app
     * service is missing (stock Bagisto) the original behaviour
     * applies, and any failure here must never break an invoice.
     */
    protected function isStockAlreadyDeducted($order): bool
    {
        if (! $order || ! class_exists(CodStockService::class)) {
            return false;
        }

        try {
            return app(CodStockServiceInterface::class)->isDeductedForOrder((int) $order->id);
        } catch (\Throwable) {
            return false;
        }
    }
}
