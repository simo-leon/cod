<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Analytics\AnalyticsServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\User\Models\Admin;

class CodConfirmerController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected AnalyticsServiceInterface $analytics,
    ) {}

    /**
     * One confirmer's page (phase 3): confirmation and delivery rates
     * plus their assigned orders.
     *
     * A confirmer may only view their own page; the confirmation
     * supervisor may view any confirmer.
     */
    public function show(Request $request, Admin $admin): View
    {
        abort_unless(bouncer()->hasPermission('cod.confirmation'), 403);

        $user = auth()->guard('admin')->user();
        $canManage = bouncer()->hasPermission('cod.assignment');

        if (! $canManage && $admin->id !== $user->id) {
            abort(403);
        }

        $status = $request->query('status');

        if (! in_array($status, $this->orders->statuses(), true)) {
            $status = null;
        }

        return view('admin::cod.confirmer')->with([
            'confirmer' => $admin,
            'rate' => $this->analytics->confirmerRate($admin),
            'orders' => $this->orders->paginatedOrdersForConfirmer($admin, $status),
            'statuses' => $this->orders->statuses(),
            'status' => $status,
            'canManage' => $canManage,
            'dashboardUrl' => route('admin.cod.index'),
            'myTasksUrl' => route('admin.cod.my-tasks.index'),
        ]);
    }
}
