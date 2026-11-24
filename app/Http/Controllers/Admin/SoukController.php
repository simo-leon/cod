<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Souk\SoukServiceInterface;
use App\Cod\Souk\SoukSettings;
use App\Http\Controllers\Controller;
use App\Models\SoukPayment;
use App\Models\SoukTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Webkul\Product\Models\Product;

class SoukController extends Controller
{
    public function __construct(
        protected SoukServiceInterface $souk,
        protected SoukSettings $settings,
    ) {}

    /**
     * Souk board: live tasks, the buyer's own tasks, trust
     * statistics per buyer, and the panel settings summary.
     *
     * Guarded by the `cod.souk` ACL key. A souk buyer sees the same
     * board, but the claim / purchase / receive buttons only work on
     * tasks they may act on; verification, payments and canceling are
     * manager-only (enforced again in the service).
     */
    public function index(): View
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $admin = auth()->guard('admin')->user();

        return view('admin::cod.souk')->with([
            'enabled' => $this->settings->isEnabled(),
            'claimTimeoutHours' => $this->settings->claimTimeoutHours(),
            'defaultPriceCap' => $this->settings->defaultPriceCap(),
            'defaultMinMarginPercent' => $this->settings->defaultMinMarginPercent(),
            'paymentModel' => $this->settings->paymentModel(),
            'tasks' => $this->souk->openTasks(),
            'myTasks' => $this->souk->isSoukBuyer($admin) ? $this->souk->tasksForBuyer($admin) : collect(),
            'buyerStats' => $this->souk->allBuyerStats(),
            'isBuyer' => $this->souk->canActAsBuyer($admin),
            'isManager' => $this->souk->canManageSouk($admin),
            'dashboardUrl' => route('admin.cod.index'),
            'settingsUrl' => route('admin.configuration.index', 'general/cod_system'),
        ]);
    }

    /**
     * Claim an assigned task. First claim wins (the service locks
     * the task row); a second buyer gets an error instead of a
     * second claim.
     */
    public function claim(SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $buyer = auth()->guard('admin')->user();

        if ($this->souk->claim($task, $buyer)) {
            return back()->with('success', trans('admin::app.cod.souk.claimed'));
        }

        return back()->with('error', trans('admin::app.cod.souk.claim-failed'));
    }

    /**
     * Record the price the buyer paid in the souk for one unit.
     */
    public function purchase(Request $request, SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $data = $request->validate([
            'purchase_price' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $buyer = auth()->guard('admin')->user();

        if ($this->souk->recordPurchase($task, $buyer, (float) $data['purchase_price'], $data['note'] ?? null)) {
            return back()->with('success', trans('admin::app.cod.souk.purchase-recorded'));
        }

        return back()->with('error', trans('admin::app.cod.souk.purchase-failed'));
    }

    /**
     * Record that the goods entered the warehouse (stock increases).
     */
    public function receive(SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $buyer = auth()->guard('admin')->user();

        if ($this->souk->receive($task, $buyer)) {
            return back()->with('success', trans('admin::app.cod.souk.received'));
        }

        return back()->with('error', trans('admin::app.cod.souk.receive-failed'));
    }

    /**
     * The warehouse controller accepts or refuses the received
     * goods. A refusal needs a reason — it is required, not
     * optional, so the refusal stays traceable.
     */
    public function verify(Request $request, SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => [Rule::requiredIf(fn () => ! $request->boolean('approve')), 'nullable', 'string', 'max:1000'],
            // M25: explicit approval of a purchase over the cap / below
            // the margin — required by the service when the flags are set.
            'confirm_override' => ['nullable', 'boolean'],
        ]);

        $controller = auth()->guard('admin')->user();

        if ($this->souk->verify($task, $controller, (bool) $data['approve'], $data['reason'] ?? null, (bool) ($data['confirm_override'] ?? false))) {
            return back()->with('success', $data['approve']
                ? trans('admin::app.cod.souk.verified')
                : trans('admin::app.cod.souk.rejected'));
        }

        return back()->with('error', trans('admin::app.cod.souk.verify-failed'));
    }

    /**
     * Record one payment to the buyer (advance or reimbursement).
     */
    public function pay(Request $request, SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'in:'.implode(',', SoukPayment::TYPES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $paidBy = auth()->guard('admin')->user();

        if ($this->souk->recordPayment($task, $paidBy, (float) $data['amount'], $data['type'], $data['note'] ?? null)) {
            return back()->with('success', trans('admin::app.cod.souk.payment-recorded'));
        }

        return back()->with('error', trans('admin::app.cod.souk.payment-failed'));
    }

    /**
     * Cancel a task that is still assigned or claimed.
     */
    public function cancel(Request $request, SoukTask $task): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = auth()->guard('admin')->user();

        if ($this->souk->cancel($task, $admin, $data['reason'] ?? null)) {
            return back()->with('success', trans('admin::app.cod.souk.canceled'));
        }

        return back()->with('error', trans('admin::app.cod.souk.cancel-failed'));
    }

    /**
     * Save the sourcing mode of one product from its admin edit
     * page (the sourcing card rendered after the product form).
     * Requires the `cod.souk` permission; the product itself is
     * untouched.
     */
    public function updateSourcing(Request $request, Product $product): RedirectResponse
    {
        abort_unless(bouncer()->hasPermission('cod.souk'), 403);

        $data = $request->validate([
            'mode' => ['required', 'in:own_stock,souk,auto'],
            'price_cap' => ['nullable', 'numeric', 'min:0'],
            'min_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->souk->setSourcing(
            (int) $product->id,
            $data['mode'],
            $data['price_cap'] !== null ? (float) $data['price_cap'] : null,
            $data['min_margin_percent'] !== null ? (float) $data['min_margin_percent'] : null,
        );

        // A product switched to "souk" (or "auto" with zero stock)
        // gets its panel task immediately instead of waiting for the
        // next scheduled scan.
        $this->souk->ensureTaskForProduct($product);

        return back()->with('success', trans('admin::app.cod.souk.sourcing-saved'));
    }
}
