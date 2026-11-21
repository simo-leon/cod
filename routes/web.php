<?php

use App\Http\Controllers\Admin\CodAssignmentController;
use App\Http\Controllers\Admin\CodCallLogController;
use App\Http\Controllers\Admin\CodConfirmerController;
use App\Http\Controllers\Admin\CodDashboardController;
use App\Http\Controllers\Admin\CodMyTasksController;
use App\Http\Controllers\Admin\CodOrderController;
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

    /**
     * COD System orders list (phase 2). Guarded by the `cod.orders` ACL key.
     */
    Route::get('cod/orders', [CodOrderController::class, 'index'])
        ->name('admin.cod.orders.index');

    /**
     * COD System confirmation tasks (phase 3). Guarded by the
     * `cod.confirmation` ACL key: a confirmer sees only their own
     * tasks, the supervisor sees every task.
     */
    Route::get('cod/my-tasks', [CodMyTasksController::class, 'index'])
        ->name('admin.cod.my-tasks.index');

    /**
     * COD System per-confirmer page (phase 3): rates and assigned
     * orders. Guarded by `cod.confirmation`; a confirmer may only open
     * their own page.
     */
    Route::get('cod/confirmers/{admin}', [CodConfirmerController::class, 'show'])
        ->name('admin.cod.confirmers.show');

    /**
     * COD System distribution rules (phase 3). Guarded by the
     * `cod.assignment` ACL key: the confirmation supervisor only.
     */
    Route::get('cod/assignment', [CodAssignmentController::class, 'index'])
        ->name('admin.cod.assignment.index');

    Route::post('cod/assignment/rules', [CodAssignmentController::class, 'storeRule'])
        ->name('admin.cod.assignment.rules.store');

    Route::delete('cod/assignment/rules/{rule}', [CodAssignmentController::class, 'destroyRule'])
        ->name('admin.cod.assignment.rules.destroy');

    Route::post('cod/assignment/distribute', [CodAssignmentController::class, 'distribute'])
        ->name('admin.cod.assignment.distribute');

    /**
     * Manual assignment of one pending order (phase 3), from the COD
     * orders list. Supervisor only (`cod.assignment`).
     */
    Route::post('cod/orders/{codOrder}/assign', [CodAssignmentController::class, 'assign'])
        ->name('admin.cod.orders.assign');

    /**
     * Record the outcome of a confirmation call (phase 3). Guarded by
     * `cod.confirmation`; a confirmer may only log calls on orders
     * assigned to them.
     */
    Route::post('cod/orders/{codOrder}/calls', [CodCallLogController::class, 'store'])
        ->name('admin.cod.orders.calls.store');
});
