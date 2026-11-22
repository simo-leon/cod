<?php

namespace App\Http\Controllers\Admin;

use App\Cod\Analytics\AnalyticsServiceInterface;
use App\Cod\Confirmation\ConfirmationServiceInterface;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Cod\Souk\SoukSettings;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class CodDashboardController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orders,
        protected ConfirmationServiceInterface $confirmation,
        protected WhatsAppServiceInterface $whatsApp,
        protected DeliveryServiceInterface $delivery,
        protected AnalyticsServiceInterface $analytics,
        protected SoukSettings $soukSettings,
    ) {}

    /**
     * COD System dashboard (phase 1: foundation).
     *
     * Guarded by the `cod` ACL key on the route (admin.cod.index) and
     * again here, so an admin without the permission gets a 403 instead
     * of the page. Every number comes from the real database; zero shows
     * as zero and a missing delivery_parcels table shows as missing
     * instead of crashing the page.
     *
     * A confirmer (has `cod.confirmation`, lacks `cod.assignment`)
     * sees only their own numbers here, like on the analytics page:
     * the three order cards count their assigned COD orders, and the
     * confirmer-rates table shows only their own row. The supervisor
     * and other staff see the team totals. The parcels card stays a
     * global delivery total: parcels carry no confirmer assignment,
     * so no scoped number exists for it, and it names no order or
     * customer.
     */
    public function index(): View
    {
        abort_unless(bouncer()->hasPermission('cod'), 403);

        $whatsAppConfigured = $this->whatsApp->isEnabled() && $this->whatsApp->getPhoneNumber();

        $user = auth()->guard('admin')->user();
        $isConfirmer = bouncer()->hasPermission('cod.confirmation');
        $canManage = bouncer()->hasPermission('cod.assignment');
        $scopedTo = ($isConfirmer && ! $canManage) ? $user->id : null;

        $assignedCounts = $scopedTo !== null
            ? $this->orders->assignedOrderCounts($scopedTo)
            : null;

        $confirmerRates = $scopedTo !== null
            ? [$this->analytics->confirmerRate($user)]
            : $this->analytics->confirmerRates();

        $modules = [
            [
                'key' => 'orders',
                'name' => trans('admin::app.cod.index.modules.orders'),
                'status' => 'ready',
            ],
            [
                'key' => 'confirmation',
                'name' => trans('admin::app.cod.index.modules.confirmation'),
                'status' => 'ready',
            ],
            [
                'key' => 'whatsapp',
                'name' => trans('admin::app.cod.index.modules.whatsapp'),
                'status' => $whatsAppConfigured ? 'configured' : 'not-configured',
            ],
            [
                'key' => 'delivery',
                'name' => trans('admin::app.cod.index.modules.delivery'),
                'status' => $this->delivery->isConfigured() ? 'configured' : 'not-configured',
            ],
            [
                'key' => 'analytics',
                'name' => trans('admin::app.cod.index.modules.analytics'),
                'status' => 'ready',
            ],
            [
                'key' => 'souk',
                'name' => trans('admin::app.cod.index.modules.souk'),
                'status' => $this->soukSettings->isEnabled() ? 'configured' : 'not-configured',
            ],
        ];

        return view('admin::cod.index')->with([
            'systemName' => $this->analytics->systemName(),
            'modules' => $modules,
            'ordersCount' => $assignedCounts['total'] ?? $this->orders->ordersCount(),
            'pendingCount' => $assignedCounts['pending'] ?? $this->orders->pendingConfirmationCount(),
            'todayOrdersCount' => $assignedCounts['today'] ?? $this->orders->todayOrdersCount(),
            'parcelsCount' => $this->delivery->parcelsCount(),
            'parcelsTableExists' => $this->delivery->parcelsTableExists(),
            'deliveryProvider' => $this->delivery->defaultProviderLabel(),
            'confirmerDailyLimit' => $this->confirmation->confirmerDailyLimit(),
            'autoAssignment' => $this->confirmation->isAutoAssignmentEnabled(),
            'ordersUrl' => route('admin.cod.orders.index'),
            'analyticsUrl' => route('admin.cod.analytics.index'),
            'settingsUrl' => route('admin.configuration.index', 'general/cod_system'),
            'canViewAnalytics' => bouncer()->hasPermission('cod.analytics'),
            'confirmerRates' => $confirmerRates,
            'myTasksUrl' => route('admin.cod.my-tasks.index'),
            'assignmentUrl' => route('admin.cod.assignment.index'),
            'canManageAssignment' => $canManage,
            'soukUrl' => route('admin.cod.souk.index'),
            'canViewSouk' => bouncer()->hasPermission('cod.souk'),
        ]);
    }
}
