<?php

namespace App\Http\Controllers\Api;

use App\Cod\Products\Exceptions\ProductImageException;
use App\Cod\Products\ProductApiService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Core\Rules\Slug;
use Webkul\Product\Contracts\Product;

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
     * Create one product of the batch, mirroring
     * CodProductCreateController step by step. Any failure is
     * returned as a result entry — it never throws, so the batch
     * continues with the next product.
     *
     * Returns ['sku' => …, 'created' => bool, 'payload' => […]].
     */
    protected function createOne(mixed $item, ProductApiService $service): array
    {
        $sku = is_array($item) ? ($item['sku'] ?? null) : null;

        try {
            $validator = Validator::make($item ?? [], $this->itemRules());

            if ($validator->fails()) {
                return $this->failure($sku, 'The given data was invalid.', $validator->errors()->toArray());
            }

            $validated = $validator->validated();

            $locales = $validated['locales'];
            $defaultLocale = $service->defaultLocaleCode();

            $errors = $service->validateLocales($locales);

            if (empty($locales[$defaultLocale]['name'] ?? null)) {
                $errors["locales.{$defaultLocale}.name"] = ["The name in the default locale ({$defaultLocale}) is required."];
            }

            foreach ($locales as $locale => $fields) {
                if (empty($fields['name'] ?? null)) {
                    $errors["locales.{$locale}.name"] = ['Each provided locale needs a name.'];
                }

                if (! empty($fields['name'])) {
                    $duplicate = $service->findDuplicateName($fields['name'], $locale);

                    if ($duplicate) {
                        $errors["locales.{$locale}.name"] = ["A product with this name already exists in locale '{$locale}': #{$duplicate['id']} (SKU {$duplicate['sku']})."];
                    }
                }
            }

            $imageCount = count($validated['image_urls'] ?? []);

            if ($imageCount > ProductApiService::MAX_IMAGES) {
                $errors['images'] = ['At most '.ProductApiService::MAX_IMAGES.' images are allowed per product.'];
            }

            if (! empty($errors)) {
                return $this->failure($sku, 'The given data was invalid.', $errors);
            }

            try {
                $imageFiles = $service->collectImageFiles(
                    [],
                    $validated['image_urls'] ?? []
                );
            } catch (ProductImageException $e) {
                return $this->failure($sku, $e->getMessage(), ['images' => [$e->getMessage()]]);
            }

            $result = $service->createProduct($validated, $imageFiles);

            $product = $result['product'];
            $defaultUrlKey = $this->defaultUrlKey($service, $product, $locales, $result['locales']);

            return [
                'sku' => $product->sku,
                'created' => true,
                'payload' => [
                    'product' => [
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'url' => $defaultUrlKey ? $service->productUrl($product, $defaultUrlKey) : null,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return $this->failure($sku, $e->getMessage());
        }
    }

    /**
     * Per-product validation rules — identical to the single-create
     * endpoint, except multipart `images[]` is not accepted in bulk
     * (JSON only, `image_urls`).
     */
    protected function itemRules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:255', new Slug, Rule::unique('products', 'sku')],
            'price' => 'required|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
            'locales' => 'required|array',
            'locales.*' => 'array',
            'locales.*.name' => 'nullable|string|min:1|max:255',
            'locales.*.url_key' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'locales.*.short_description' => 'nullable|string',
            'locales.*.description' => 'nullable|string',
            'locales.*.meta_title' => 'nullable|string|max:255',
            'locales.*.meta_description' => 'nullable|string',
            'locales.*.meta_keywords' => 'nullable|string|max:255',
            'image_urls' => 'nullable|array|max:'.ProductApiService::MAX_IMAGES,
            'image_urls.*' => ['string', 'url', 'regex:/^https?:\/\//i'],
        ];
    }

    /**
     * Failure result entry for one product of the batch.
     */
    protected function failure(?string $sku, string $message, array $errors = []): array
    {
        $payload = ['error' => $message];

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return [
            'sku' => $sku,
            'created' => false,
            'payload' => $payload,
        ];
    }

    /**
     * The url_key stored for the default locale (for the response
     * URL). It was generated by the service when not provided, so
     * read it back from the database. Same logic as
     * CodProductCreateController::defaultUrlKey().
     */
    protected function defaultUrlKey(ProductApiService $service, Product $product, array $locales, array $writtenLocales): ?string
    {
        $defaultLocale = $service->defaultLocaleCode();

        if (! empty($locales[$defaultLocale]['url_key'])) {
            return $locales[$defaultLocale]['url_key'];
        }

        if (! in_array('url_key', $writtenLocales[$defaultLocale] ?? [], true)) {
            return null;
        }

        return DB::table('product_attribute_values as pav')
            ->join('attributes', 'attributes.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $product->id)
            ->where('pav.locale', $defaultLocale)
            ->where('attributes.code', 'url_key')
            ->value('pav.text_value');
    }
}
