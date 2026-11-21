<?php

namespace App\Cod\Orders;

use App\Models\CodOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

/**
 * Read-only order figures for the COD System, plus the phase 2 import
 * paths that record store orders in the COD System.
 *
 * Every number comes from the real orders table. Nothing here invents
 * data. Imports are idempotent: a store order is recorded once, keyed by
 * its `external_id` (the store order increment_id).
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

    /**
     * Whether the order was paid cash on delivery.
     */
    public function isCodOrder(Order $order): bool;

    /**
     * Record one store order in the COD System (webhook path). Returns null
     * when the order is not a cash-on-delivery order. Never throws: an
     * import failure must never break checkout.
     */
    public function importOrder(Order $order, string $source = CodOrder::SOURCE_WEBHOOK): ?CodOrder;

    /**
     * Record one order from an API payload (external_id plus customer,
     * total and status fields). Returns null when the payload has no
     * usable external_id. When the payload matches a local store order,
     * the local order is the authoritative snapshot.
     */
    public function importFromPayload(array $data, string $source = CodOrder::SOURCE_API): ?CodOrder;

    /**
     * Import every cash-on-delivery store order that has no COD record yet.
     * Returns ['imported' => n, 'already_imported' => n, 'failed' => n].
     */
    public function importPendingOrders(int $limit = 500): array;

    /**
     * COD workflow statuses, in display order.
     */
    public function statuses(): array;

    /**
     * Number of COD records per status.
     */
    public function statusCounts(): array;

    /**
     * Imported COD orders, newest first, optionally filtered by status.
     */
    public function paginatedOrders(?string $status = null, int $perPage = 20): LengthAwarePaginator;

    /**
     * Orders assigned to one confirmer, newest first, optionally
     * filtered by status. Used by the per-confirmer page.
     */
    public function paginatedOrdersForConfirmer(Admin $admin, ?string $status = null, int $perPage = 20): LengthAwarePaginator;

    /**
     * Confirmation tasks: assigned orders, pending confirmation first,
     * then newest first. When a confirmer id is given, only that
     * confirmer's tasks are returned; otherwise every assigned order.
     */
    public function paginatedTasks(?int $confirmerId = null, int $perPage = 20): LengthAwarePaginator;

    /**
     * Secret key of the COD import endpoint, from the admin settings
     * (general.cod_system.settings.import_secret_key), or null when no key
     * is configured. Never hard-coded, never logged.
     */
    public function getImportSecretKey(): ?string;
}
