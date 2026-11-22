<?php

namespace App\Providers;

use App\Cod\Souk\SoukServiceInterface;
use App\Listeners\AssignZeroStockToSouk;
use App\Listeners\ImportOrderToCod;
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
use Webkul\Theme\ViewRenderEventManager;

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

        // Record cash-on-delivery orders in the COD System (phase 2)
        Event::listen('checkout.order.save.after', [ImportOrderToCod::class, 'handle']);

        // Souk (market sourcing): a product that just hit zero
        // available stock is assigned to the souk panel
        // automatically — on a product edit and on a new order.
        Event::listen('catalog.product.update.after', [AssignZeroStockToSouk::class, 'handleProductUpdate']);
        Event::listen('checkout.order.save.after', [AssignZeroStockToSouk::class, 'handleOrder']);

        // Souk (market sourcing): a COD order reaches the souk panel
        // only once it is confirmed — on creation (an
        // already-confirmed store order imported for the first time)
        // and on the status transition (confirmed by call, by
        // WhatsApp, or by a store status sync). An unconfirmed order
        // creates no task at checkout (see handleOrder above).
        Event::listen('eloquent.created: App\Models\CodOrder', [AssignZeroStockToSouk::class, 'handleCodOrderConfirmed']);
        Event::listen('eloquent.updated: App\Models\CodOrder', [AssignZeroStockToSouk::class, 'handleCodOrderConfirmed']);

        // Souk sourcing card on the admin product edit page (after
        // the product form): current stock, effective mode, and the
        // mode / cap / margin form.
        Event::listen('bagisto.admin.catalog.product.edit.after', function (ViewRenderEventManager $manager) {
            $product = $manager->getParam('product');

            if (! $product) {
                return;
            }

            $souk = app(SoukServiceInterface::class);

            $manager->addTemplate('admin::cod.souk-product-card', [
                'product' => $product,
                'sourcing' => $souk->sourcingFor((int) $product->id),
                'stockQty' => $souk->stockQty((int) $product->id),
                'effectiveMode' => $souk->effectiveMode($product),
                'openTask' => $souk->openTaskForProduct((int) $product->id),
                'canEditSourcing' => bouncer()->hasPermission('cod.souk'),
            ]);
        });

        // Souk tasks card on the admin store order view (above the
        // order items): which items come from the souk, who the
        // buyer is, the purchase price and the status. Only rendered
        // when the order actually has souk tasks.
        Event::listen('bagisto.admin.sales.order.left_component.before', function (ViewRenderEventManager $manager) {
            $order = $manager->getParam('order');

            if (! $order || ! bouncer()->hasPermission('cod.souk')) {
                return;
            }

            $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();

            $tasks = app(SoukServiceInterface::class)->tasksForOrderProductIds($productIds);

            if ($tasks->isEmpty()) {
                return;
            }

            $manager->addTemplate('admin::cod.souk-order-card', [
                'order' => $order,
                'tasks' => $tasks,
            ]);
        });

        // Krayin CRM listeners
        Event::listen('sales.order.update-status.after', [KrayinCrmListener::class, 'handleOrderStatusUpdated']);
        Event::listen('checkout.order.save.after', [KrayinCrmListener::class, 'handleOrderCreated']);
        Event::listen('customer.registration.after', [KrayinCrmListener::class, 'handleCustomerRegistered']);
    }
}
