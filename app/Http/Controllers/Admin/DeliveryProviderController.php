<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryParcel;
use App\Services\Delivery\DeliveryProviderManager;
use Illuminate\Http\RedirectResponse;
use Webkul\Sales\Models\Order;

class DeliveryProviderController extends Controller
{
    /**
     * Test the configured delivery provider connection (GET list-status).
     * The token is never shown in the response.
     */
    public function testConnection(DeliveryProviderManager $manager): RedirectResponse
    {
        $provider = $manager->defaultProvider();

        if ($provider === null) {
            session()->flash('error', trans('admin::app.sales.orders.view.delivery.not-configured'));

            return redirect()->back();
        }

        $result = $provider->testConnection();

        session()->flash(
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? trans('admin::app.sales.orders.view.delivery.test-success')
                : trans('admin::app.sales.orders.view.delivery.test-failed', ['message' => $result['message']])
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
            session()->flash('success', trans('admin::app.sales.orders.view.delivery.send-success'));
        } else {
            session()->flash(
                'error',
                trans('admin::app.sales.orders.view.delivery.send-failed')
                .($parcel?->error_message ? ' '.$parcel->error_message : '')
            );
        }

        return redirect()->back();
    }
}
