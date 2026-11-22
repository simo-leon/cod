<?php

namespace App\Cod\Delivery;

use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\CodOrder;
use App\Models\DeliveryParcel;
use App\Services\Delivery\DeliveryProviderManager;
use App\Services\Delivery\TrackingStatusMapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;

/**
 * Unified tracking sync (COD System phase 6).
 *
 * One parcel sync does three things, in order, each guarded so a
 * failure in a later step never undoes or breaks an earlier one:
 *  1. Refresh the parcel from its provider through
 *     DeliveryProviderManager::refreshTracking() — the provider's
 *     raw status is normalized to one unified value and stored in
 *     `last_status`; a provider failure is stored in
 *     `error_message` and the parcel status is left untouched.
 *  2. Sync the linked COD order status (see STATUS_MAP below).
 *  3. Notify the customer on WhatsApp for the new delivery event
 *     (WhatsAppService::sendStatusNotification(), once per event
 *     per order). A notification failure never changes the parcel
 *     or COD status and never throws.
 *
 * Status map (unified parcel status => COD workflow status /
 * notification event). CodOrder has no out-for-delivery status, so
 * out_for_delivery keeps the COD status at sent_to_delivery and
 * only fires its own notification:
 * - pending / in_transit => sent_to_delivery, event 'shipped'
 * - out_for_delivery     => sent_to_delivery, event 'out_for_delivery'
 * - delivered            => delivered, event 'delivered'
 * - returned             => returned, event 'returned'
 * - cancelled            => no COD change, no notification: a
 *   carrier-cancelled parcel is ambiguous (merchant-cancelled?
 *   customer refused?) and must not silently cancel or return the
 *   order — an admin decides.
 *
 * Forward-only: the COD status only moves while it is confirmed
 * or sent_to_delivery. A delivered, returned or canceled COD order
 * is never moved back, and a COD order still pending confirmation
 * or already canceled is never notified: parcels are created at
 * checkout, before the customer confirms, so a shipped notification
 * must wait for the confirmation (confirmed / sent_to_delivery).
 *
 * Idempotent: a re-run with no status change writes nothing to the
 * parcel, changes no COD status, and sends no notification twice
 * (the *_notification_sent_at columns guard that).
 */
class TrackingSyncService implements TrackingSyncServiceInterface
{
    /**
     * Unified parcel status => [COD status, notification event].
     */
    public const STATUS_MAP = [
        TrackingStatusMapper::PENDING => [CodOrder::STATUS_SENT_TO_DELIVERY, 'shipped'],
        TrackingStatusMapper::IN_TRANSIT => [CodOrder::STATUS_SENT_TO_DELIVERY, 'shipped'],
        TrackingStatusMapper::OUT_FOR_DELIVERY => [CodOrder::STATUS_SENT_TO_DELIVERY, 'out_for_delivery'],
        TrackingStatusMapper::DELIVERED => [CodOrder::STATUS_DELIVERED, 'delivered'],
        TrackingStatusMapper::RETURNED => [CodOrder::STATUS_RETURNED, 'returned'],
    ];

    /**
     * COD statuses from which the sync may move the order forward.
     * pending_confirmation is deliberately absent: parcels are
     * created at checkout, before the customer confirms.
     */
    public const SYNCABLE_COD_STATUSES = [
        CodOrder::STATUS_CONFIRMED,
        CodOrder::STATUS_SENT_TO_DELIVERY,
    ];

    public function __construct(
        protected DeliveryProviderManager $manager,
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

    public function syncParcel(DeliveryParcel $parcel): array
    {
        $result = [
            'success' => false,
            'status' => $parcel->last_status,
            'changed' => false,
            'cod_order_id' => null,
            'cod_status_changed' => false,
            'notification' => null,
            'skipped' => false,
            'error' => null,
        ];

        if ($parcel->status !== 'sent' || ! $parcel->parcel_code) {
            $result['skipped'] = true;
            $result['error'] = 'Parcel was not sent or has no tracking code.';

            return $result;
        }

        try {
            $storedStatus = TrackingStatusMapper::normalize($parcel->provider, $parcel->last_status);

            // Final state: no provider call (nothing left to learn),
            // but still run the COD sync + notification from the
            // stored status — e.g. a delivered notification that
            // failed earlier must still be retried.
            if ($storedStatus !== null && in_array($storedStatus, TrackingStatusMapper::FINAL_STATUSES, true)) {
                $result['success'] = true;
                $result['status'] = $storedStatus;

                $cod = $this->syncCodOrder($parcel, $storedStatus);
                $result['cod_order_id'] = $cod['cod_order_id'];
                $result['cod_status_changed'] = $cod['cod_status_changed'];
                $result['notification'] = $cod['notification'];

                return $result;
            }

            $refresh = $this->manager->refreshTracking($parcel);

            $result['success'] = $refresh['success'];
            $result['status'] = $refresh['status'];
            $result['changed'] = $refresh['changed'];
            $result['error'] = $refresh['error'];

            if (! $refresh['success']) {
                return $result;
            }

            $status = TrackingStatusMapper::normalize($parcel->provider, $parcel->fresh()->last_status);

            if ($status === null) {
                return $result;
            }

            $result['status'] = $status;

            $cod = $this->syncCodOrder($parcel, $status);
            $result['cod_order_id'] = $cod['cod_order_id'];
            $result['cod_status_changed'] = $cod['cod_status_changed'];
            $result['notification'] = $cod['notification'];

            return $result;
        } catch (\Throwable $e) {
            Log::warning('COD tracking sync: failed to sync parcel', [
                'parcel_id' => $parcel->id,
                'provider' => $parcel->provider,
                'error' => $e->getMessage(),
            ]);

            $result['error'] = $e->getMessage();

            return $result;
        }
    }

    public function syncAll(): array
    {
        $counts = ['checked' => 0, 'updated' => 0, 'notified' => 0, 'failed' => 0, 'skipped' => 0];

        if (! Schema::hasTable('delivery_parcels')) {
            return $counts;
        }

        $parcels = DeliveryParcel::query()
            ->where('status', 'sent')
            ->whereNotNull('parcel_code')
            ->orderBy('id')
            ->get();

        foreach ($parcels as $parcel) {
            $counts['checked']++;

            try {
                $result = $this->syncParcel($parcel);
            } catch (\Throwable $e) {
                // syncParcel() never throws by contract; this is a
                // last-resort guard so one bad parcel can never stop
                // the whole run.
                Log::warning('COD tracking sync: unexpected parcel failure', [
                    'parcel_id' => $parcel->id,
                    'error' => $e->getMessage(),
                ]);

                $counts['failed']++;

                continue;
            }

            if ($result['skipped']) {
                $counts['skipped']++;
            } elseif (! $result['success']) {
                $counts['failed']++;
            } else {
                if ($result['changed'] || $result['cod_status_changed']) {
                    $counts['updated']++;
                }

                if (($result['notification']['success'] ?? false) === true && ! ($result['notification']['already_sent'] ?? false)) {
                    $counts['notified']++;
                }
            }
        }

        return $counts;
    }

    /**
     * Sync the COD order linked to one parcel for a unified parcel
     * status. Never throws: notification failures are reported, not
     * raised.
     *
     * @return array{cod_order_id: ?int, cod_status_changed: bool, notification: ?array}
     */
    protected function syncCodOrder(DeliveryParcel $parcel, string $status): array
    {
        $out = ['cod_order_id' => null, 'cod_status_changed' => false, 'notification' => null];

        $mapping = self::STATUS_MAP[$status] ?? null;

        // Cancelled (and anything unmapped): no COD change, no
        // notification — see the class docblock.
        if ($mapping === null) {
            return $out;
        }

        $codOrder = $this->findCodOrder($parcel);

        if ($codOrder === null) {
            return $out;
        }

        $out['cod_order_id'] = $codOrder->id;

        // A COD order still waiting for confirmation, or already
        // canceled, is never moved or notified by tracking: parcels
        // are created at checkout, before confirmation.
        if (! in_array($codOrder->status, self::SYNCABLE_COD_STATUSES, true)) {
            return $out;
        }

        [$codStatus, $event] = $mapping;

        if ($codOrder->status !== $codStatus) {
            // Forward-only: sent_to_delivery -> delivered / returned,
            // confirmed -> sent_to_delivery. A delivered order never
            // goes back to sent_to_delivery.
            $forward = ($codOrder->status === CodOrder::STATUS_CONFIRMED && $codStatus === CodOrder::STATUS_SENT_TO_DELIVERY)
                || ($codOrder->status === CodOrder::STATUS_SENT_TO_DELIVERY && in_array($codStatus, [CodOrder::STATUS_DELIVERED, CodOrder::STATUS_RETURNED], true));

            if ($forward) {
                $codOrder->status = $codStatus;
                $codOrder->save();
                $out['cod_status_changed'] = true;
            }
        }

        try {
            $notification = $this->whatsapp->sendStatusNotification($codOrder, $event, $parcel->parcel_code);

            $notification['already_sent'] = str_contains((string) ($notification['message'] ?? ''), 'already sent');
            $out['notification'] = $notification;
        } catch (\Throwable $e) {
            // A notification failure must never break the sync.
            Log::warning('COD tracking sync: status notification failed', [
                'cod_order_id' => $codOrder->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            $out['notification'] = ['success' => false, 'message' => $e->getMessage(), 'message_id' => null];
        }

        return $out;
    }

    /**
     * The COD order for a parcel: by linked store order id, falling
     * back to the store order increment id.
     */
    protected function findCodOrder(DeliveryParcel $parcel): ?CodOrder
    {
        if (! Schema::hasTable('cod_orders')) {
            return null;
        }

        $codOrder = CodOrder::query()->where('order_id', $parcel->order_id)->first();

        if ($codOrder) {
            return $codOrder;
        }

        $order = Order::query()->find($parcel->order_id);

        if ($order === null) {
            return null;
        }

        return CodOrder::query()->where('external_id', $order->increment_id)->first();
    }
}
