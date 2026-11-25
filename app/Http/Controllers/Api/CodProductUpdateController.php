<?php

namespace App\Http\Controllers\Api;

use App\Cod\Products\Exceptions\ProductImageException;
use App\Cod\Products\ProductApiService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Secure endpoint that updates one store product's per-locale content
 * and/or stock, for the AI product rewrite (300 products, fr/ar/en).
 *
 * POST /api/cod/products/{id}
 *
 * Auth: the secret key configured in Admin → Configuration → General →
 * COD System → Product Secret Key, sent as the `X-COD-Product-Key`
 * header or as a Bearer token. Requests without a key, with a wrong
 * key, or while no key is configured are rejected with 401. The key
 * is never hard-coded and never logged. Generate/rotate it with
 * `php artisan cod:generate-product-key`.
 *
 * Payload (JSON) — only the fields actually sent are updated, the
 * rest is left untouched:
 * {
 *   "locales": {
 *     "fr": {"name": "…", "url_key": "…", "short_description": "…",
 *            "description": "…", "meta_title": "…",
 *            "meta_description": "…", "meta_keywords": "…"},
 *     "ar": {"name": "…", "description": "…"},
 *     "en": {"name": "…"}
 *   },
 *   "stock": 10,
 *   "image_urls": ["https://…/2.png", "https://…/3.png"]
 * }
 * `url_key` must be a lowercase slug (a-z, 0-9, dashes) and unique
 * per locale; a colliding or reserved slug is rejected with 422.
 * At least one of `locales`, `stock` or `image_urls` must be present.
 *
 * `image_urls` APPENDS images (at most 10 images in total per
 * product): already-attached images are skipped by content hash,
 * so re-sending the same URLs adds nothing twice. It never removes
 * an existing image. A URL that fails to download rejects the
 * whole request with 422 — the artisan backfill command
 * (`jemla:add-doshift-images`) is the resilient path: one bad URL
 * is recorded and the rest still upload.
 *
 * What this endpoint deliberately does NOT accept: price, sku, type,
 * categories, status, images (multipart upload) — those fields are
 * rejected with 422. Prices and categories are managed in the admin.
 *
 * Success (200):
 * {
 *   "product": {"id": 307, "sku": "20200303", "type": "simple"},
 *   "updated": {
 *     "locales": {"fr": ["name", "description"], "ar": ["name"]},
 *     "stock": {"inventory_source_id": 1, "qty": 10},
 *     "images": {"added": 3, "skipped_already_attached": 0,
 *                "failed": [], "total_images": 4}
 *   }
 * }
 * Unknown product id → 404. Bad payload → 422 with field errors.
 *
 * Example:
 *   curl -X POST http://localhost:8000/api/cod/products/307 \
 *     -H "X-COD-Product-Key: <key>" \
 *     -H "Content-Type: application/json" \
 *     -d '{"locales": {"ar": {"name": "ماكينة حلاقة HTC AT-129"}} , "stock": 10}'
 */
class CodProductUpdateController extends Controller
{
    /**
     * Fields the update endpoint refuses (managed elsewhere).
     * `image_urls` is NOT refused: it appends images, see below.
     */
    protected const REJECTED_FIELDS = [
        'price',
        'special_price',
        'sku',
        'type',
        'categories',
        'status',
        'images',
    ];

    public function __invoke(Request $request, int $id, ProductApiService $service): JsonResponse
    {
        $providedKey = $request->header(ProductApiService::HEADER)
            ?? $request->bearerToken();

        if (! $service->isAuthorized($providedKey)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $product = $service->findProduct($id);

        if (! $product) {
            return response()->json([
                'message' => "Product {$id} not found.",
            ], 404);
        }

        $rejected = array_values(array_intersect(
            self::REJECTED_FIELDS,
            array_keys($request->all())
        ));

        if (! empty($rejected)) {
            return response()->json([
                'message' => 'These fields are not accepted on this endpoint: '.implode(', ', $rejected).'.',
                'errors' => array_fill_keys($rejected, ['This field is not accepted on the product update endpoint.']),
            ], 422);
        }

        $validated = $request->validate([
            'locales' => 'nullable|array',
            'locales.*' => 'array',
            'locales.*.name' => 'nullable|string|min:1|max:255',
            'locales.*.url_key' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'locales.*.short_description' => 'nullable|string',
            'locales.*.description' => 'nullable|string',
            'locales.*.meta_title' => 'nullable|string|max:255',
            'locales.*.meta_description' => 'nullable|string',
            'locales.*.meta_keywords' => 'nullable|string|max:255',
            'stock' => 'nullable|integer|min:0',
            'image_urls' => 'nullable|array|max:'.ProductApiService::MAX_IMAGES,
            'image_urls.*' => ['string', 'url', 'regex:/^https?:\/\//i'],
        ]);

        $locales = $validated['locales'] ?? [];
        $stock = $validated['stock'] ?? null;
        $imageUrls = $validated['image_urls'] ?? [];

        $errors = $service->validateLocales($locales, $product->id);

        if (empty($locales) && $stock === null && empty($imageUrls)) {
            $errors['payload'] = ['Nothing to update: provide "locales", "stock" and/or "image_urls".'];
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $updated = $service->updateProduct($product, $locales, $stock);

        if (! empty($imageUrls)) {
            try {
                $updated['images'] = $service->appendImages($product, $imageUrls);
            } catch (ProductImageException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['image_urls' => [$e->getMessage()]],
                ], 422);
            }
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'type' => $product->type,
            ],
            'updated' => $updated,
        ], 200);
    }
}
