<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Confirmation\CallLogServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\CodCallLog;
use App\Models\CodOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CodCallLogController extends Controller
{
    public function __construct(
        protected CallLogServiceInterface $calls,
    ) {}

    /**
     * Record the outcome of one confirmation call (phase 3).
     *
     * A confirmer may only log calls on orders assigned to them; the
     * confirmation supervisor may log on any order.
     */
    public function store(Request $request, CodOrder $codOrder): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.confirmation'), 403);

        $user = auth()->guard('admin')->user();
        $canManage = bouncer()->hasPermission('cod.assignment');

        if (! $canManage && $codOrder->assigned_to !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'result' => ['required', 'in:'.implode(',', CodCallLog::RESULTS)],
            'note' => ['nullable', 'string', 'max:1000'],
            'callback_at' => ['nullable', 'date'],
        ]);

        try {
            $this->calls->recordCall(
                $codOrder,
                $user,
                $data['result'],
                $data['note'] ?? null,
                $data['callback_at'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', trans('admin::app.cod.my-tasks.call-recorded'));
    }
}
