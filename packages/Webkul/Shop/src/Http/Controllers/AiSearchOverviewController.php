<?php

namespace Webkul\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\MagicAI\Services\AiSearchOverviewService;
use Webkul\Shop\Http\Requests\AiSearchOverviewRequest;

class AiSearchOverviewController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected AiSearchOverviewService $aiSearchOverviewService
    ) {}

    /**
     * Handle the AJAX request for AI search overview.
     */
    public function generate(AiSearchOverviewRequest $request): JsonResponse
    {
        $enabled = (bool) core()->getConfigData('magic_ai.general.settings.enabled')
            && (bool) core()->getConfigData('magic_ai.storefront_features.ai_search_overview.enabled');

        if (! $enabled) {
            return new JsonResponse([
                'enabled' => false,
                'has_overview' => false,
                'message' => 'AI Overview is disabled.',
            ]);
        }

        $query = (string) $request->input('query');
        $result = $this->aiSearchOverviewService->generate($query);

        if (! $result) {
            return new JsonResponse([
                'enabled' => true,
                'has_overview' => false,
            ]);
        }

        return new JsonResponse([
            'enabled' => true,
            'has_overview' => true,
            'summary' => $result['summary'],
            'products' => $result['products'],
        ]);
    }
}
