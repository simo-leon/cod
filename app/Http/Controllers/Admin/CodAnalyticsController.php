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
     * (admin.cod.analytics.index) and again here, so an admin without
     * the permission gets a 403 instead of the page. Every figure
     * comes from real COD data or from admin settings; a figure
     * without data shows as unavailable, never as an invented number.
     * The filters (period, city, delivery provider, product) apply to
     * every figure on the page.
     */
    public function index(Request $request): View
    {
        abort_unless(bouncer()->hasPermission('cod.analytics'), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'city' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
        ]);

        $analytics = $this->analytics->analytics($request->only(['from', 'to', 'city', 'provider', 'sku']));

        return view('admin::cod.analytics')->with([
            'analytics' => $analytics,
            'systemName' => $this->analytics->systemName(),
            'dashboardUrl' => route('admin.cod.index'),
            'settingsUrl' => route('admin.configuration.index', 'general/cod_system/analytics'),
        ]);
    }
}
