<?php

namespace App\Cod\Souk;

use App\Models\SoukPayment;
use App\Models\SoukProductSourcing;
use App\Models\SoukTask;
use Illuminate\Database\Eloquent\Collection;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

interface SoukServiceInterface
{
    public function sourcingFor(int $productId): ?SoukProductSourcing;

    public function setSourcing(int $productId, string $mode, ?float $priceCap, ?float $minMarginPercent): SoukProductSourcing;

    public function effectiveMode(Product $product): string;

    public function stockQty(int $productId): int;

    /**
     * Sale price of one unit: the price the customer actually paid
     * — the order item's unit price when the task is linked to a
     * COD order, otherwise the active special price, else the list
     * price. Null when the product has no flat row yet.
     */
    public function salePrice(int $productId, ?int $codOrderId = null): ?float;

    public function openTaskForProduct(int $productId): ?SoukTask;

    public function ensureTaskForProduct(Product $product, ?int $codOrderId = null, int $qty = 1): ?SoukTask;

    /**
     * Make sure a product that has to come from the souk has a live
     * task — but only when a confirmed COD order still needs it.
     * Returns the task, or null when no confirmed order needs the
     * product. This is the shared confirmation gate for the
     * automatic paths (the product-update listener and the
     * 30-minute scan): neither may create a task for a product
     * nobody ordered. The manual admin actions (switching a product
     * to "souk" mode, confirming an order) call
     * ensureTaskForProduct() directly and are not gated here.
     */
    public function ensureTaskForConfirmedOrder(Product $product): ?SoukTask;

    public function checkZeroStock(): array;

    public function claim(SoukTask $task, Admin $buyer): bool;

    public function recordPurchase(SoukTask $task, Admin $buyer, float $price, ?string $note = null): bool;

    public function receive(SoukTask $task, Admin $buyer): bool;

    public function verify(SoukTask $task, Admin $controller, bool $approve, ?string $reason = null, bool $overrideApproved = false): bool;

    public function recordPayment(SoukTask $task, Admin $paidBy, float $amount, string $type, ?string $note = null): ?SoukPayment;

    public function cancel(SoukTask $task, Admin $admin, ?string $reason = null): bool;

    public function escalateOverdue(): array;

    public function isSoukBuyer(Admin $admin): bool;

    public function isWarehouseController(Admin $admin): bool;

    public function canManageSouk(Admin $admin): bool;

    public function canActAsBuyer(Admin $admin): bool;

    /**
     * @return Collection<int, SoukTask>
     */
    public function openTasks(): Collection;

    /**
     * @return Collection<int, SoukTask>
     */
    public function tasksForBuyer(Admin $buyer): Collection;

    public function buyerStats(Admin $buyer): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allBuyerStats(): array;

    public function tasksForCodOrder(int $codOrderId): Collection;

    /**
     * Confirmed orders needing souk goods with no live task yet.
     */
    public function uncoveredConfirmedOrdersCount(): int;

    public function tasksForOrderProductIds(array $productIds): Collection;
}
