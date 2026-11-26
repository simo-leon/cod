<?php

namespace App\Providers;

use App\Cod\Ai\ConversationAgent;
use App\Cod\Ai\ConversationAgentInterface;
use App\Cod\Ai\OpenAiCompatibleReplyClassifier;
use App\Cod\Ai\ReplyIntentClassifierInterface;
use App\Cod\Analytics\AnalyticsService;
use App\Cod\Analytics\AnalyticsServiceInterface;
use App\Cod\Automation\AutomationSettings;
use App\Cod\Confirmation\AssignmentService;
use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Confirmation\CallLogService;
use App\Cod\Confirmation\CallLogServiceInterface;
use App\Cod\Confirmation\ConfirmationService;
use App\Cod\Confirmation\ConfirmationServiceInterface;
use App\Cod\Conversations\ConversationService;
use App\Cod\Conversations\ConversationServiceInterface;
use App\Cod\Delivery\DeliveryService;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Delivery\TrackingSyncService;
use App\Cod\Delivery\TrackingSyncServiceInterface;
use App\Cod\Orders\OrderService;
use App\Cod\Orders\OrderServiceInterface;
use App\Cod\Souk\SoukService;
use App\Cod\Souk\SoukServiceInterface;
use App\Cod\Souk\SoukSettings;
use App\Cod\WhatsApp\WhatsAppService;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Services\AbandonedCart\AbandonedCartService;
use App\Services\AbandonedCart\AbandonedCartServiceInterface;
use App\Services\Promotions\PromotionService;
use App\Services\Promotions\PromotionServiceInterface;
use App\Services\StoreFeatures\StoreFeatureService;
use App\Services\StoreFeatures\StoreFeatureServiceInterface;
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

        $this->app->singleton(ReplyIntentClassifierInterface::class, OpenAiCompatibleReplyClassifier::class);

        $this->app->singleton(ConversationAgentInterface::class, ConversationAgent::class);

        $this->app->singleton(AutomationSettings::class);

        $this->app->singleton(ConversationServiceInterface::class, ConversationService::class);

        $this->app->singleton(DeliveryServiceInterface::class, DeliveryService::class);

        $this->app->singleton(TrackingSyncServiceInterface::class, TrackingSyncService::class);

        $this->app->singleton(AnalyticsServiceInterface::class, AnalyticsService::class);

        $this->app->singleton(SoukSettings::class);

        $this->app->singleton(SoukServiceInterface::class, SoukService::class);

        $this->app->singleton(PromotionServiceInterface::class, PromotionService::class);

        $this->app->singleton(StoreFeatureServiceInterface::class, StoreFeatureService::class);

        $this->app->singleton(AbandonedCartServiceInterface::class, AbandonedCartService::class);
    }
}
