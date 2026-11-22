<?php

namespace App\Cod\Delivery;

use App\Models\DeliveryParcel;

/**
 * Unified tracking sync (phase 6): refresh one parcel from its
 * delivery provider, sync the linked COD order status, and notify
 * the customer on WhatsApp.
 */
interface TrackingSyncServiceInterface
{
    /**
     * Sync one parcel: refresh its tracking status, update the
     * linked COD order status, and send the customer notification
     * for the new status when one applies. Never throws: a provider
     * or notification failure is reported in the result and stored
     * on the parcel, never raised.
     *
     * @return array{success: bool, status: ?string, changed: bool, cod_order_id: ?int, cod_status_changed: bool, notification: ?array, skipped: bool, error: ?string}
     */
    public function syncParcel(DeliveryParcel $parcel): array;

    /**
     * Sync every sent parcel that is not in a final state, plus
     * final-state parcels whose customer notification is still
     * pending (no provider call for those — only the COD sync and
     * the notification). Never throws.
     *
     * @return array{checked: int, updated: int, notified: int, failed: int, skipped: int}
     */
    public function syncAll(): array;
}
