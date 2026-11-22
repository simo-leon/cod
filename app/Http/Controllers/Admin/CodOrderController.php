<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\SoukTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CodOrderController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected AssignmentServiceInterface $assignment,
    ) {}

    /**
     * Imported COD orders, filterable by COD workflow status.
     *
     * Guarded by the `cod.orders` ACL key on the route
     * (admin.cod.orders.index) and again here, so an admin without the
     * permission gets a 403 instead of the page. Each row links to the
     * original store order in the admin. The supervisor can also assign
     * orders manually and distribute the unassigned ones from here.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.orders'), 403);

        $status = $request->query('status');

        if (! in_array($status, $this->orders->statuses(), true)) {
            $status = null;
        }

        $orders = $this->orders->paginatedOrders($status);

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
            'statusCounts' => $this->orders->statusCounts(),
            'status' => $status,
            'dashboardUrl' => route('admin.cod.index'),
            'confirmers' => $this->assignment->confirmers(),
            'canManageAssignment' => bouncer()->hasPermission('cod.assignment'),
            'unassignedCount' => $this->assignment->unassignedCount(),
            'soukTasks' => $soukTasks,
            'canViewSouk' => bouncer()->hasPermission('cod.souk'),
        ]);
    }
}
