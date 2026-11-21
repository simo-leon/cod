<?php

use App\Http\Controllers\Admin\CodDashboardController;
use App\Http\Controllers\Admin\DeliveryProviderController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

/**
 * Admin routes for the delivery providers module (Jemla En Ligne).
 * Same middleware and admin URL prefix as the package admin routes.
 */
Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    Route::get('delivery-providers/test-connection', [DeliveryProviderController::class, 'testConnection'])
        ->name('admin.delivery-providers.test-connection');

    Route::post('sales/orders/{id}/send-to-delivery', [DeliveryProviderController::class, 'sendParcel'])
        ->name('admin.sales.orders.send-to-delivery');

    /**
     * COD System dashboard (phase 1). Guarded by the `cod` ACL key.
     */
    Route::get('cod', [CodDashboardController::class, 'index'])
        ->name('admin.cod.index');
});
