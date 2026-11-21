<?php

namespace App\Providers;

use App\Cod\Analytics\AnalyticsService;
use App\Cod\Analytics\AnalyticsServiceInterface;
use App\Cod\Confirmation\AssignmentService;
use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\CallLogService;
use App\Cod\Confirmation\CallLogServiceInterface;
use App\Cod\Confirmation\ConfirmationService;
use App\Cod\Confirmation\ConfirmationServiceInterface;
use App\Cod\Delivery\DeliveryService;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Orders\OrderService;
use App\Cod\Orders\OrderServiceInterface;
use App\Cod\WhatsApp\WhatsAppService;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the COD System modules (phase 1: foundation).
 *
 * Each module has one contract and one service, bound as singletons.
 * Delivery reuses the existing DeliveryProviderManager in
 * app/Services/Delivery/ — it is injected into DeliveryService, not
 * rebuilt. New modules are added here with one more binding each.
 */
class CodServiceProvider extends ServiceProvider
{
    /**
     * Register the COD System services.
     */
    public function register(): void
    {
        $this->app->singleton(OrderServiceInterface::class, OrderService::class);

        $this->app->singleton(ConfirmationServiceInterface::class, ConfirmationService::class);

        $this->app->singleton(AssignmentServiceInterface::class, AssignmentService::class);

        $this->app->singleton(CallLogServiceInterface::class, CallLogService::class);

        $this->app->singleton(WhatsAppServiceInterface::class, WhatsAppService::class);

        $this->app->singleton(DeliveryServiceInterface::class, DeliveryService::class);

        $this->app->singleton(AnalyticsServiceInterface::class, AnalyticsService::class);
    }
}
