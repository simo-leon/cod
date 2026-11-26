<?php

namespace App\Cod\Inventory;

use App\Models\CodOrder;
use Webkul\Sales\Models\Order;

interface CodStockServiceInterface
{
    /**
     * Whether the confirmation deduction already ran for the COD
     * order linked to this store order. The shipment and invoice
     * repositories ask this before decrementing the stock a second
     * time.
     */
    public function isDeductedForOrder(int $orderId): bool;

    /**
     * Deduct the ordered quantities from the physical stock when a
     * COD order is confirmed. Overselling is allowed, so the stock
     * may go negative.
     */
    public function deductForConfirmedOrder(CodOrder $codOrder): void;

    /**
     * Put back what the confirmation deduction took, when a COD
     * order is refused or cancelled.
     */
    public function restoreForCanceledOrder(CodOrder $codOrder): void;

    /**
     * Put back what the confirmation deduction took, when the store
     * order itself is cancelled or returned from the admin.
     */
    public function restoreForStoreOrder(Order $order): void;
}
