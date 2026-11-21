<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\CallLogServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodMyTasksController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected AssignmentServiceInterface $assignment,
        protected CallLogServiceInterface $calls,
    ) {}

    /**
     * Confirmation task list (phase 3).
     *
     * A confirmer sees only the orders assigned to them, pending
     * confirmation first. The confirmation supervisor (ACL key
     * `cod.assignment`) sees every task and can filter by confirmer.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.confirmation'), 403);

        $user = auth()->guard('admin')->user();
        $canManage = bouncer()->hasPermission('cod.assignment');

        $selectedConfirmer = null;

        if ($canManage) {
            $filterId = (int) $request->query('confirmer', 0);

            if ($filterId > 0) {
                $selectedConfirmer = $this->assignment->confirmers()->firstWhere('id', $filterId);
            }

            $confirmerId = $selectedConfirmer?->id;
        } else {
            $confirmerId = $user->id;
        }

        return view('admin::cod.my-tasks')->with([
            'tasks' => $this->orders->paginatedTasks($confirmerId),
            'confirmers' => $canManage ? $this->assignment->confirmers() : collect(),
            'canManage' => $canManage,
            'selectedConfirmer' => $selectedConfirmer,
            'results' => $this->calls->results(),
            'statuses' => $this->orders->statuses(),
            'dashboardUrl' => route('admin.cod.index'),
        ]);
    }
}
