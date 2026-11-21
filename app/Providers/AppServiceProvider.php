<?php

namespace App\Providers;

use App\Listeners\KrayinCrmListener;
use App\Listeners\SendOrderToDeliveryProvider;
use App\Models\OrderStatusHistory;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use Webkul\Sales\Models\Order;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips', '')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        // Set COD orders to pending_confirmation on creation
        Event::listen('checkout.order.save.after', function ($order) {
            if ($order->payment && in_array($order->payment->method, ['cashondelivery', 'moneytransfer', 'cod'])) {
                $previousStatus = $order->status;
                $order->status = Order::STATUS_PENDING_CONFIRMATION;
                $order->save();

                Event::dispatch('sales.order.update-status.after', [$order, Order::STATUS_PENDING_CONFIRMATION, $previousStatus]);
            }
        });

        // Record every status change in order_status_histories
        Event::listen('sales.order.update-status.after', function ($order, $newStatus = null, $previousStatus = null) {
            try {
                $currentNewStatus = $newStatus ?? $order->status;
                $admin = Auth::guard('admin')->user();

                if ($admin) {
                    $source = request()->routeIs('admin.sales.orders.mass_update') ? 'mass_update' : 'view_page';
                } elseif (! $previousStatus) {
                    $source = 'checkout';
                } else {
                    $source = 'system';
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'old_status' => $previousStatus,
                    'new_status' => $currentNewStatus,
                    'admin_id' => $admin?->id,
                    'actor_name' => $admin?->name ?? ($source === 'checkout' ? 'النظام (طلب جديد)' : 'النظام'),
                    'source' => $source,
                ]);
            } catch (\Throwable $e) {
                // Silently fail — history logging must never break the main flow
                Log::warning('OrderStatusHistory: failed to record', [
                    'order_id' => $order->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Send cash-on-delivery orders to the configured delivery provider
        Event::listen('checkout.order.save.after', [SendOrderToDeliveryProvider::class, 'handle']);

        // Krayin CRM listeners
        Event::listen('sales.order.update-status.after', [KrayinCrmListener::class, 'handleOrderStatusUpdated']);
        Event::listen('checkout.order.save.after', [KrayinCrmListener::class, 'handleOrderCreated']);
        Event::listen('customer.registration.after', [KrayinCrmListener::class, 'handleCustomerRegistered']);
    }
}
