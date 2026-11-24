<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\CallLogServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\CodOrder;
use App\Models\SoukTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CodOrderController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected AssignmentServiceInterface $assignment,
        protected CallLogServiceInterface $calls,
    ) {}

    /**
     * Imported COD orders, filterable by COD workflow status.
     *
     * Guarded by the `cod.orders` ACL key on the route
     * (admin.cod.orders.index) and again here, so an admin without the
     * permission gets a 403 instead of the page. Each row links to the
     * COD order detail page. The supervisor can also assign
     * orders manually and distribute the unassigned ones from here.
     *
     * A confirmer (has `cod.confirmation`, lacks `cod.assignment`)
     * sees only the orders assigned to them — the list, and the
     * per-status counts on it, are scoped to their id, exactly like
     * the order detail page. Unassigned orders are excluded for
     * them too: the detail page 403s those, and their task list
     * only ever shows assigned orders, so showing them here would
     * be dead links. The supervisor and other staff keep the full
     * unscoped list.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.orders'), 403);

        $status = $request->query('status');

        if (! in_array($status, $this->orders->statuses(), true)) {
            $status = null;
        }

        $user = auth()->guard('admin')->user();
        $isConfirmer = bouncer()->hasPermission('cod.confirmation');
        $canManage = bouncer()->hasPermission('cod.assignment');
        $scopedTo = ($isConfirmer && ! $canManage) ? $user->id : null;

        $orders = $this->orders->paginatedOrders($status, 20, $scopedTo);

        // Souk tasks linked to the orders on this page (guarded: on
        // an install where the souk migration has not run yet there
        // is no souk_tasks table — show nothing instead of crashing).
        $soukTasks = collect();

        if (Schema::hasTable('souk_tasks')) {
            $soukTasks = SoukTask::query()
                ->with(['product', 'buyer'])
                ->whereIn('cod_order_id', $orders->pluck('id')->all())
                ->get()
                ->groupBy('cod_order_id');
        }

        return view('admin::cod.orders')->with([
            'orders' => $orders,
            'statuses' => $this->orders->statuses(),
            'statusCounts' => $this->orders->statusCounts($scopedTo),
            'status' => $status,
            'dashboardUrl' => route('admin.cod.index'),
            'confirmers' => $this->assignment->confirmers(),
            'canManageAssignment' => $canManage,
            // T9: the count is only computed (and passed) for staff who
            // may see it — a confirmer never sees unassigned orders.
            'unassignedCount' => $canManage ? $this->assignment->unassignedCount() : 0,
            'soukTasks' => $soukTasks,
            'canViewSouk' => bouncer()->hasPermission('cod.souk'),
        ]);
    }

    /**
     * One COD order: everything a confirmer needs to call the customer
     * and log the result — customer, items, totals, call history.
     *
     * This page exists because there is no per-order view a confirmer
     * can open otherwise: the store order page needs the
     * `sales.orders.view` permission, which the Confirmer role does
     * not have, so Bouncer aborts with a 401 when a confirmer clicks
     * an order. The route is deliberately NOT in the ACL map (like the
     * call-logging route): access is enforced here instead.
     *
     * Access: anyone with `cod.orders` or `cod.confirmation`. A
     * confirmer (has `cod.confirmation`, lacks `cod.assignment`) may
     * open only the orders assigned to them; the confirmation
     * supervisor (`cod.assignment`) may open any order, as may other
     * staff with `cod.orders` — they already see the full unscoped
     * orders list.
     */
    public function show(CodOrder $codOrder): View
    {
        abort_unless(
            bouncer()->hasPermission('cod.orders') || bouncer()->hasPermission('cod.confirmation'),
            403
        );

        $user = auth()->guard('admin')->user();
        $canManage = bouncer()->hasPermission('cod.assignment');
        $isConfirmer = bouncer()->hasPermission('cod.confirmation');

        if ($isConfirmer && ! $canManage && $codOrder->assigned_to !== $user->id) {
            abort(403);
        }

        $codOrder->load(['order.items', 'callLogs.confirmer', 'assignedTo']);

        // P-A3: the souk tasks linked to this COD order, so the
        // confirmer sees whether the goods come from the souk.
        $soukTasks = collect();

        if (Schema::hasTable('souk_tasks')) {
            $soukTasks = SoukTask::query()
                ->with(['product', 'buyer'])
                ->where('cod_order_id', $codOrder->id)
                ->orderBy('id')
                ->get();
        }

        return view('admin::cod.order-show')->with([
            'codOrder' => $codOrder,
            'items' => $this->orderItems($codOrder),
            'results' => $this->calls->results(),
            'canManage' => $canManage,
            'canLogCall' => $isConfirmer && ($canManage || $codOrder->assigned_to === $user->id),
            'canViewMyTasks' => $isConfirmer,
            'canViewStoreOrder' => bouncer()->hasPermission('sales.orders.view'),
            'ordersUrl' => route('admin.cod.orders.index'),
            'myTasksUrl' => route('admin.cod.my-tasks.index'),
            'dashboardUrl' => route('admin.cod.index'),
            'soukTasks' => $soukTasks,
            'canViewSouk' => bouncer()->hasPermission('cod.souk'),
            'soukUrl' => route('admin.cod.souk.index'),
        ]);
    }

    /**
     * Re-send the WhatsApp confirmation message for one pending
     * order (creative addition): when the first send failed, the
     * confirmer used to have no manual action — the only retry was a
     * re-import. The send is forced past the once-only guard; a
     * failed send leaves confirmation_sent_at empty as before.
     */
    public function resendConfirmation(CodOrder $codOrder): RedirectResponse
    {
        abort_unless(
            bouncer()->hasPermission('cod.confirmation') || bouncer()->hasPermission('cod.assignment'),
            403
        );

        $user = auth()->guard('admin')->user();

        if (bouncer()->hasPermission('cod.confirmation') && ! bouncer()->hasPermission('cod.assignment') && $codOrder->assigned_to !== $user->id) {
            abort(403);
        }

        $result = app(WhatsAppServiceInterface::class)->sendConfirmation($codOrder, true);

        if (! $result['success']) {
            return back()->with('error', trans('admin::app.cod.order-show.resend-failed', ['error' => $result['message']]));
        }

        return back()->with('success', trans('admin::app.cod.order-show.resend-sent'));
    }

    /**
     * Items of one COD order: from its linked store order items, or
     * from the import payload when there is no linked store order.
     * Each item: ['sku', 'name', 'qty', 'price' (null when unknown)].
     * Same source as AnalyticsService::orderItems().
     */
    protected function orderItems(CodOrder $codOrder): array
    {
        $items = [];

        if ($codOrder->order) {
            foreach ($codOrder->order->items as $item) {
                if (empty($item->sku)) {
                    continue;
                }

                $items[] = [
                    'sku' => (string) $item->sku,
                    'name' => (string) ($item->name ?? ''),
                    'qty' => max(1, (int) $item->qty_ordered),
                    'price' => is_numeric($item->price) ? (float) $item->price : null,
                ];
            }
        }

        if (empty($items) && is_array($codOrder->payload)) {
            foreach ($codOrder->payload['items'] ?? [] as $item) {
                if (! is_array($item) || empty($item['sku'])) {
                    continue;
                }

                $items[] = [
                    'sku' => (string) $item['sku'],
                    'name' => (string) ($item['name'] ?? ''),
                    'qty' => max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1)),
                    'price' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null,
                ];
            }
        }

        return $items;
    }
}
