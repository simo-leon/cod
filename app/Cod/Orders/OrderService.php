<?php

namespace App\Cod\Orders;

use Webkul\Sales\Models\Order;

class OrderService implements OrderServiceInterface
{
    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int
    {
        return Order::count();
    }

    /**
     * Number of orders waiting for confirmation.
     */
    public function pendingConfirmationCount(): int
    {
        return Order::where('status', Order::STATUS_PENDING_CONFIRMATION)->count();
    }

    /**
     * Number of orders created today.
     */
    public function todayOrdersCount(): int
    {
        return Order::whereDate('created_at', today())->count();
    }
}
