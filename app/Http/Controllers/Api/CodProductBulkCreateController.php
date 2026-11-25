<?php

namespace App\Http\Controllers\Api;

use App\Cod\Products\ProductApiService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk version of the single product-create endpoint, built for the
 * doshift import (thousands of products): one request creates up to
 * 50 simple products, sequentially, reusing the single-create
 * validation and service methods.
 *
 * POST /api/cod/products/bulk
 *
 * Auth: same secret key as the single-create endpoint (Admin →
 * Configuration → General → COD System → Product Secret Key), sent
 * as the `X-COD-Product-Key` header or as a Bearer token. Missing,
 * wrong or unconfigured key → 401. Generate/rotate it with
 * `php artisan cod:generate-product-key`.
 *
 * Every product is created INACTIVE (status 0) unless `status: 1`
 * is sent explicitly for that product, so nothing is published
 * without Simo's approval. Type is always `simple`; attribute
 * family is the store's default.
 *
 * Payload (JSON only — no multipart `images[]` in bulk):
 * {
 *   "products": [                 // required, 1–50 items
 *     {
 *       "sku": "DOS-001",        // required, unique
 *       "price": 149,            // required, >= 0 (DH)
 *       "stock": 10,             // optional, default 0
 *       "status": 0,             // optional, default 0 (inactive)
 *       "categories": [5, 12],   // optional, existing category ids
 *       "locales": {             // required; the default locale's
 *                                // name is required, other locales
 *                                // are optional but need a name
 *                                // when present
 *         "fr": {"name": "…", "url_key": "…",
 *                "short_description": "…", "description": "…",
 *                "meta_title": "…", "meta_description": "…",
 *                "meta_keywords": "…"},
 *         "ar": {"name": "…"},
 *         "en": {"name": "…"}
 *       },
 *       "image_urls": ["https://…/photo1.jpg"]  // optional,
 *                                              // max 10 images
 *                                              // per product
 *     }
 *   ]
 * }
 * Per-product rules are exactly the single-create rules (same
 * locale validation, same duplicate SKU/name guards, same image
 * download via `collectImageFiles`). A failure in one product
 * (validation, duplicate, image download 429/timeout) is recorded
 * and does NOT stop the rest.
 *
 * The per-product creation lives in
 * ProductApiService::createBulkItem() — the same method the bundled
 * doshift import command (`jemla:import-doshift`) uses, so the
 * endpoint and the command can never drift apart.
 *
 * Success (200 — when auth and the top-level shape are valid):
 * {
 *   "created": 4,
 *   "failed": 1,
 *   "results": [
 *     {"index": 0, "sku": "DOS-001", "created": true,
 *      "product": {"id": 512, "sku": "DOS-001",
 *                  "url": "http://localhost:8000/slug-fr"}},
 *     {"index": 1, "sku": "DOS-002", "created": false,
 *      "error": "The sku has already been taken.",
 *      "errors": {"sku": ["The sku has already been taken."]}}
 *   ]
 * }
 * Bad top-level shape (missing/empty `products`, more than 50
 * items) → 422 with field errors.
 *
 * Example:
 *   curl -X POST http://localhost:8000/api/cod/products/bulk \
 *     -H "X-COD-Product-Key: <key>" \
 *     -H "Content-Type: application/json" \
 *     -d '{"products": [{"sku": "DOS-001", "price": 149,
 *          "stock": 10, "locales": {"fr": {"name": "Produit test"}}}]}'
 */
class CodProductBulkCreateController extends Controller
{
    /**
     * Maximum products accepted in one bulk request. Kept small
     * enough that one request (image downloads included) finishes
     * well inside a normal PHP-FPM execution limit.
     */
    public const MAX_PRODUCTS = 50;

    public function __invoke(Request $request, ProductApiService $service): JsonResponse
    {
        $providedKey = $request->header(ProductApiService::HEADER)
            ?? $request->bearerToken();

        if (! $service->isAuthorized($providedKey)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        // A bulk of image downloads can outrun the default PHP
        // execution limit on FPM; lift it for this request when the
        // function is available (it is a no-op under CLI).
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $request->validate([
            'products' => 'required|array|min:1|max:'.self::MAX_PRODUCTS,
            'products.*' => 'array',
        ]);

        $results = [];
        $created = 0;

        foreach ($request->input('products') as $index => $item) {
            $result = $this->createOne($item, $service);

            if ($result['created']) {
                $created++;
            }

            // Keep the original order in the response.
            $results[] = [
                'index' => $index,
                'sku' => $result['sku'],
                'created' => $result['created'],
            ] + $result['payload'];
        }

        return response()->json([
            'created' => $created,
            'failed' => count($results) - $created,
            'results' => $results,
        ], 200);
    }

    /**
     * Create one product of the batch. The whole per-product path
     * (validation, duplicate guards, image download, creation)
     * lives in the service, shared with the bundled import command
     * — see ProductApiService::createBulkItem(). Any failure is
     * returned as a result entry, never thrown, so the batch
     * continues with the next product.
     *
     * Returns ['sku' => …, 'created' => bool, 'payload' => […]].
     */
    protected function createOne(mixed $item, ProductApiService $service): array
    {
        return $service->createBulkItem($item);
    }
}
