<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Orders\OrderServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodOrderController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
    ) {}

    /**
     * Imported COD orders, filterable by COD workflow status.
     *
     * Guarded by the `cod.orders` ACL key on the route
     * (admin.cod.orders.index) and again here, so an admin without the
     * permission gets a 403 instead of the page. Each row links to the
     * original store order in the admin.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.orders'), 403);

        $status = $request->query('status');

        if (! in_array($status, $this->orders->statuses(), true)) {
            $status = null;
        }

        return view('admin::cod.orders')->with([
            'orders' => $this->orders->paginatedOrders($status),
            'statuses' => $this->orders->statuses(),
            'statusCounts' => $this->orders->statusCounts(),
            'status' => $status,
            'dashboardUrl' => route('admin.cod.index'),
        ]);
    }
}
