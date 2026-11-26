<?php

namespace Webkul\Sales\Repositories;

use App\Cod\Inventory\CodStockService;
use App\Cod\Inventory\CodStockServiceInterface;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Eloquent\Repository;

class ShipmentItemRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model(): string
    {
        return 'Webkul\Sales\Contracts\ShipmentItem';
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
            ->where('channel_id', $data['shipment']->order->channel->id)
            ->first();

        if ($orderedInventory) {
            if (($orderedQty = $orderedInventory->qty - $data['qty']) < 0) {
                $orderedQty = 0;
            }

            $orderedInventory->update(['qty' => $orderedQty]);
        }

        $inventory = $data['product']->inventories()
            ->where('vendor_id', $data['vendor_id'])
            ->where('inventory_source_id', $data['shipment']->inventory_source_id)
            ->first();

        if (! $inventory) {
            return;
        }

        /*
         * Jemla: the physical stock was already deducted when the
         * COD order was confirmed
         * (App\Cod\Inventory\CodStockService), so the shipment must
         * not take it a second time — its floor at zero would also
         * silently erase the negative balance overselling allows.
         * The ordered reservation above was still reduced.
         */
        if ($this->isStockAlreadyDeducted($data['shipment']->order)) {
            Event::dispatch('catalog.product.update.after', $data['product']);

            return;
        }

        if (($qty = $inventory->qty - $data['qty']) < 0) {
            $qty = 0;
        }

        $inventory->update(['qty' => $qty]);

        Event::dispatch('catalog.product.update.after', $data['product']);
    }

    /**
     * Whether the physical stock of this order was already deducted
     * at COD confirmation. Defensive on purpose: when the app
     * service is missing (stock Bagisto) the original behaviour
     * applies, and any failure here must never break a shipment.
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
