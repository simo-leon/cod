<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Delivery\TrackingSyncServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\DeliveryParcel;
use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;

class DeliveryProviderController extends Controller
{
    /**
     * Test the configured delivery provider connection (GET list-status).
     * The token is never shown in the response.
     */
    public function testConnection(DeliveryProviderManager $manager): RedirectResponse
    {
        // H7 (widened by P-A1, round 2): testing the connection calls
        // an external, rate-limited API — any logged-in admin must not
        // be able to trigger it. `sales.orders.view` is enough now:
        // the Delivery Manager role holds it (CodRolesSeeder) so they
        // can test the provider from the order page, while admins with
        // neither sales permission still get 403. Sending parcels and
        // refreshing tracking stay edit-only (see below).
        abort_unless(
            bouncer()->hasPermission('sales.orders.edit')
                || bouncer()->hasPermission('sales.orders.view'),
            403
        );

        $provider = $manager->defaultProvider();

        if ($provider === null) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.not-configured'));

            return redirect()->back();
        }

        $result = $provider->testConnection();

        session()->flash(
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? trans('admin::app.sales.orders.view.delivery.test-success', ['provider' => $provider->getLabel()])
                : trans('admin::app.sales.orders.view.delivery.test-failed', ['provider' => $provider->getLabel(), 'message' => $result['message']])
        );

        return redirect()->back();
    }

    /**
     * Manually (re)send one order to the delivery provider.
     */
    public function sendParcel(int $id, DeliveryProviderManager $manager): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('sales.orders.edit'), 403);

        $order = Order::findOrFail($id);

        if (($order->payment?->method ?? null) !== 'cashondelivery') {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.not-cod'));

            return redirect()->back();
        }

        if (! $manager->isConfigured()) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.not-configured'));

            return redirect()->back();
        }

        $parcel = $manager->sendOrder($order, true);

        if ($parcel && $parcel->status === 'sent') {
            session()->flash('success', trans('admin::app.sales.orders.view.delivery.send-success', ['provider' => $manager->defaultProvider()?->getLabel()]));
        } else {
            session()->flash(
                'error',
                trans('admin::app.sales.orders.view.delivery.send-failed', ['provider' => $manager->defaultProvider()?->getLabel()])
                .($parcel?->error_message ? ' '.$parcel->error_message : '')
            );
        }

        return redirect()->back();
    }

    /**
     * Cancel the latest sent parcel of one order at its provider
     * (M12). Only shown for providers that support cancellation.
     */
    public function cancelParcel(int $id, DeliveryProviderManager $manager): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('sales.orders.edit'), 403);

        $order = Order::findOrFail($id);

        $parcel = DeliveryParcel::where('order_id', $order->id)
            ->where('status', 'sent')
            ->latest()
            ->first();

        if (! $parcel) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.refresh-no-parcel'));

            return redirect()->back();
        }

        $result = $manager->cancelParcel($parcel);

        session()->flash(
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? trans('admin::app.sales.orders.view.delivery.cancel-success', ['provider' => $manager->provider($parcel->provider)?->getLabel()])
                : trans('admin::app.sales.orders.view.delivery.cancel-failed', ['message' => $result['message']])
        );

        return redirect()->back();
    }

    /**
     * Refresh the tracking status of the latest parcel of one order.
     * Uses the same unified sync as `cod:sync-tracking` (phase 6),
     * so a manual refresh also updates the linked COD order status
     * and sends the customer notification when the status changed.
     * On provider failure the order page still loads and the failure
     * is flashed, never a 500.
     */
    public function refreshTracking(int $id, TrackingSyncServiceInterface $sync): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('sales.orders.edit'), 403);

        $order = Order::findOrFail($id);

        if (! Schema::hasTable('delivery_parcels')) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.migration-pending'));

            return redirect()->back();
        }

        $parcel = DeliveryParcel::where('order_id', $order->id)
            ->latest()
            ->first();

        if (! $parcel) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.refresh-no-parcel'));

            return redirect()->back();
        }

        if (! $parcel->parcel_code) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.refresh-no-code'));

            return redirect()->back();
        }

        $result = $sync->syncParcel($parcel);

        if ($result['success']) {
            if (($result['notification']['success'] ?? false) === true && ! ($result['notification']['already_sent'] ?? false)) {
                session()->flash(
                    'success',
                    trans('admin::app.sales.orders.view.delivery.refresh-notified', ['status' => $result['status'] ?? ''])
                );
            } elseif ($result['status']) {
                session()->flash(
                    'success',
                    trans('admin::app.sales.orders.view.delivery.refresh-success', ['status' => $result['status']])
                );
            } else {
                session()->flash('success', trans('admin::app.sales.orders.view.delivery.refresh-success-no-change'));
            }
        } else {
            session()->flash(
                'error',
                trans('admin::app.sales.orders.view.delivery.refresh-failed', ['message' => $result['error'] ?? ''])
            );
        }

        return redirect()->back();
    }
}
