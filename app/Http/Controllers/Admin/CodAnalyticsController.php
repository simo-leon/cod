<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Analytics\AnalyticsServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodAnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsServiceInterface $analytics,
    ) {}

    /**
     * COD analytics page (phase 7).
     *
     * Guarded by the `cod.analytics` ACL key on the route
     * (admin.cod.analytics.index) and again here — a confirmer
     * (`cod.confirmation`) is also let in, but only ever sees their
     * own numbers: every figure is filtered to the orders assigned
     * to them, the team table shows only their row, and the store
     * inventory and the settings links are hidden from them. The
     * supervisor (or anyone with `cod.assignment`) sees everything.
     * Every figure comes from real COD data or from admin settings;
     * a figure without data shows as unavailable, never as an
     * invented number. The filters (period, city, delivery provider,
     * product) apply to every figure on the page, and for a scoped
     * confirmer they stay inside their own assigned orders.
     *
     * The settings link points at the COD System section page
     * (`general/cod_system`, two segments): the configuration
     * routes only take two segments, so a three-segment link such
     * as `general/cod_system/analytics` falls through to the
     * download route and returns a 500.
     */
    public function index(Request $request): View
    {
        abort_unless(
            bouncer()->hasPermission('cod.analytics') || bouncer()->hasPermission('cod.confirmation'),
            403
        );

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'city' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'product_sort' => ['nullable', 'in:most-ordered,least-ordered'],
        ]);

        $user = auth()->guard('admin')->user();

        // The confirmation supervisor (cod.assignment) sees every
        // order; anyone else admitted here — a confirmer — sees only
        // the orders assigned to them.
        $canManage = bouncer()->hasPermission('cod.assignment');
        $scopedTo = $canManage ? null : $user->id;

        $filters = $request->only(['from', 'to', 'city', 'provider', 'sku', 'product_sort']);

        if ($scopedTo !== null) {
            $filters['assigned_to'] = $scopedTo;
        }

        $analytics = $this->analytics->analytics($filters);

        return view('admin::cod.analytics')->with([
            'analytics' => $analytics,
            'systemName' => $this->analytics->systemName(),
            'dashboardUrl' => route('admin.cod.index'),
            'settingsUrl' => route('admin.configuration.index', 'general/cod_system'),
            'isScoped' => $scopedTo !== null,
        ]);
    }
}
