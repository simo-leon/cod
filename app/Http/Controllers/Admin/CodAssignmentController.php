<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\ConfirmationServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\CodAssignmentRule;
use App\Models\CodOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\User\Models\Admin;

class CodAssignmentController extends Controller
{
    public function __construct(
        protected AssignmentServiceInterface $assignment,
        protected ConfirmationServiceInterface $confirmation,
    ) {}

    /**
     * Distribution rules (phase 3), managed by the confirmation
     * supervisor only (ACL key `cod.assignment`).
     */
    public function index(): View
    {
        abort_unless(bouncer()->hasPermission('cod.assignment'), 403);

        $percentageRules = $this->assignment->percentageRules();

        return view('admin::cod.assignment')->with([
            'confirmers' => $this->assignment->confirmers(),
            'percentageRules' => $percentageRules,
            'weightsTotal' => $percentageRules->sum('weight'),
            'productRules' => $this->assignment->productRules(),
            'unassignedCount' => $this->assignment->unassignedCount(),
            'dailyLimit' => $this->confirmation->confirmerDailyLimit(),
            'assignedToday' => $percentageRules
                ->mapWithKeys(fn ($rule) => [
                    $rule->admin_id => $rule->confirmer
                        ? $this->assignment->assignedTodayCount($rule->confirmer)
                        : 0,
                ]),
            'dashboardUrl' => route('admin.cod.index'),
            'ordersUrl' => route('admin.cod.orders.index'),
        ]);
    }

    /**
     * Create a percentage or a product rule.
     */
    public function storeRule(Request $request): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.assignment'), 403);

        $data = $request->validate([
            'type' => ['required', 'in:'.CodAssignmentRule::TYPE_PERCENTAGE.','.CodAssignmentRule::TYPE_PRODUCT],
            'admin_id' => ['required', 'exists:admins,id'],
            'weight' => ['required_if:type,'.CodAssignmentRule::TYPE_PERCENTAGE, 'nullable', 'integer', 'min:0', 'max:100'],
            'product_sku' => ['required_if:type,'.CodAssignmentRule::TYPE_PRODUCT, 'nullable', 'string', 'max:255', 'exists:products,sku'],
        ]);

        $admin = Admin::findOrFail($data['admin_id']);

        if (! $this->assignment->isConfirmer($admin)) {
            return back()->withErrors([
                'admin_id' => trans('admin::app.cod.assignment.not-a-confirmer'),
            ]);
        }

        if ($data['type'] === CodAssignmentRule::TYPE_PERCENTAGE) {
            $this->assignment->savePercentageRule($admin, (int) $data['weight']);

            return back()->with('success', trans('admin::app.cod.assignment.percentage-rule-saved'));
        }

        $rule = $this->assignment->saveProductRule($admin, $data['product_sku']);

        if (! $rule) {
            return back()->withErrors([
                'product_sku' => trans('admin::app.cod.assignment.product-rule-exists'),
            ]);
        }

        return back()->with('success', trans('admin::app.cod.assignment.product-rule-saved'));
    }

    /**
     * Delete one distribution rule.
     */
    public function destroyRule(CodAssignmentRule $rule): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.assignment'), 403);

        $this->assignment->deleteRule($rule);

        return back()->with('success', trans('admin::app.cod.assignment.rule-deleted'));
    }

    /**
     * Distribute every unassigned pending order, then go to the orders
     * list to see the result.
     */
    public function distribute(): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.assignment'), 403);

        $result = $this->assignment->assignUnassigned();

        return redirect()->route('admin.cod.orders.index')->with(
            'success',
            trans('admin::app.cod.assignment.distributed', [
                'assigned' => $result['assigned'],
                'skipped' => $result['skipped'],
            ]),
        );
    }

    /**
     * Supervisor override: assign one pending order to a chosen
     * confirmer, from the COD orders list.
     */
    public function assign(Request $request, CodOrder $codOrder): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.assignment'), 403);

        $data = $request->validate([
            'confirmer_id' => ['required', 'exists:admins,id'],
        ]);

        $admin = Admin::findOrFail($data['confirmer_id']);

        // M9: tell the supervisor when this overwrites an existing
        // assignee or pushes the confirmer over the daily limit — the
        // manual override is allowed, but never silent.
        $previousAssignee = $codOrder->assignedTo;
        $wasOverLimit = $this->assignment->reachedDailyLimit($admin);

        if (! $this->assignment->manualAssign($codOrder, $admin)) {
            return back()->withErrors([
                'confirmer_id' => trans('admin::app.cod.assignment.cannot-assign'),
            ]);
        }

        $message = trans('admin::app.cod.assignment.assigned', ['name' => $admin->name]);

        if ($previousAssignee && $previousAssignee->id !== $admin->id) {
            $message .= ' '.trans('admin::app.cod.assignment.reassigned-from', ['name' => $previousAssignee->name]);
        }

        if ($wasOverLimit) {
            $message .= ' '.trans('admin::app.cod.assignment.over-daily-limit');
        }

        return back()->with('success', $message);
    }
}
