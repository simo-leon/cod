<?php

use App\Http\Controllers\Admin\CodAnalyticsController;
use App\Http\Controllers\Admin\CodAssignmentController;
use App\Http\Controllers\Admin\CodCallLogController;
use App\Http\Controllers\Admin\CodConfirmerController;
use App\Http\Controllers\Admin\CodConversationController;
use App\Http\Controllers\Admin\CodDashboardController;
use App\Http\Controllers\Admin\CodMyTasksController;
use App\Http\Controllers\Admin\CodOrderController;
use App\Http\Controllers\Admin\DeliveryProviderController;
use App\Http\Controllers\Admin\SoukController;
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

    Route::post('sales/orders/{id}/refresh-delivery-tracking', [DeliveryProviderController::class, 'refreshTracking'])
        ->name('admin.sales.orders.refresh-delivery-tracking');

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
     * COD System order detail (confirmer fix). Deliberately NOT in the
     * ACL map: the controller enforces `cod.orders`/`cod.confirmation`
     * plus the assignment rule, so a confirmer opens only their own
     * orders — the store order page (`sales.orders.view`) stays closed
     * to them, because the Confirmer role has no `sales.*` permission.
     */
    Route::get('cod/orders/{codOrder}', [CodOrderController::class, 'show'])
        ->name('admin.cod.orders.show');

    /**
     * COD System WhatsApp conversations inbox. Guarded by the
     * `cod.conversations` ACL key: the full message thread of every
     * conversation, a manual reply form, and the handoff button that
     * stops the AI for that conversation.
     */
    Route::get('cod/conversations', [CodConversationController::class, 'index'])
        ->name('admin.cod.conversations.index');

    Route::get('cod/conversations/{conversation}', [CodConversationController::class, 'show'])
        ->name('admin.cod.conversations.show');

    Route::post('cod/conversations/{conversation}/reply', [CodConversationController::class, 'reply'])
        ->name('admin.cod.conversations.reply');

    Route::post('cod/conversations/{conversation}/handoff', [CodConversationController::class, 'handoff'])
        ->name('admin.cod.conversations.handoff');

    /**
     * COD System analytics (phase 7). Guarded by the `cod.analytics`
     * ACL key.
     */
    Route::get('cod/analytics', [CodAnalyticsController::class, 'index'])
        ->name('admin.cod.analytics.index');

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

    /**
     * Souk (market sourcing) board. Guarded by the `cod.souk` ACL
     * key: souk buyers see what is available and what they claimed,
     * the warehouse controller verifies goods and records payments.
     */
    Route::get('cod/souk', [SoukController::class, 'index'])
        ->name('admin.cod.souk.index');

    Route::post('cod/souk/tasks/{task}/claim', [SoukController::class, 'claim'])
        ->name('admin.cod.souk.tasks.claim');

    Route::post('cod/souk/tasks/{task}/purchase', [SoukController::class, 'purchase'])
        ->name('admin.cod.souk.tasks.purchase');

    Route::post('cod/souk/tasks/{task}/receive', [SoukController::class, 'receive'])
        ->name('admin.cod.souk.tasks.receive');

    Route::post('cod/souk/tasks/{task}/verify', [SoukController::class, 'verify'])
        ->name('admin.cod.souk.tasks.verify');

    Route::post('cod/souk/tasks/{task}/pay', [SoukController::class, 'pay'])
        ->name('admin.cod.souk.tasks.pay');

    Route::post('cod/souk/tasks/{task}/cancel', [SoukController::class, 'cancel'])
        ->name('admin.cod.souk.tasks.cancel');

    /**
     * Save the sourcing mode of one product from its admin edit page
     * (the sourcing card rendered after the product form). Guarded by
     * the `cod.souk` ACL key.
     */
    Route::post('catalog/products/{product}/sourcing', [SoukController::class, 'updateSourcing'])
        ->name('admin.cod.souk.sourcing.update');
});
