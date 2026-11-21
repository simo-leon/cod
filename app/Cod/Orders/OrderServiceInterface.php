<?php

namespace App\Cod\Orders;

/**
 * Read-only order figures for the COD System.
 *
 * Every number comes from the real orders table. Nothing here invents
 * data, and nothing here changes an order: confirmation, assignment and
 * delivery flows live in their own modules and arrive in later phases.
 */
interface OrderServiceInterface
{
    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int;

    /**
     * Number of orders waiting for confirmation.
     */
    public function pendingConfirmationCount(): int;

    /**
     * Number of orders created today.
     */
    public function todayOrdersCount(): int;
}
