<?php

namespace App\Listeners;

use App\Services\KrayinCrmService;

class KrayinCrmListener
{
    /**
     * Create a new listener instance.
     */
    public function __construct(
        protected KrayinCrmService $krayinCrmService
    ) {}

    /**
     * Handle order created event.
     */
    public function handleOrderCreated(mixed $order): void
    {
        $this->krayinCrmService->syncOrder($order);
    }

    /**
     * Handle customer registered event.
     */
    public function handleCustomerRegistered(mixed $customer): void
    {
        $this->krayinCrmService->syncCustomer($customer);
    }
}
