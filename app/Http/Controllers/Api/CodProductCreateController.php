<?php

namespace App\Http\Controllers\Api;

use App\Cod\Products\Exceptions\ProductImageException;
use App\Cod\Products\ProductApiService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Core\Rules\Slug;
use Webkul\Product\Contracts\Product;

/**
 * Secure endpoint that creates a new simple product, for the
 * Telegram supplier pipeline (a supplier posts a product, the
 * assistant checks it is new and adds it to the store).
 *
 * POST /api/cod/products
 *
 * Auth: same secret key as the update endpoint (Admin →
 * Configuration → General → COD System → Product Secret Key), sent
 * as the `X-COD-Product-Key` header or as a Bearer token. Missing,
 * wrong or unconfigured key → 401. Generate/rotate it with
 * `php artisan cod:generate-product-key`.
 *
 * The product is created INACTIVE (status 0) unless `status: 1` is
 * sent explicitly, so nothing is published without Simo's approval.
 * Type is always `simple`; attribute family is the store's default.
 *
 * Payload (JSON, or multipart/form-data when uploading files):
 * {
 *   "sku": "SUP-001",              // required, unique
 *   "price": 149,                  // required, >= 0 (DH)
 *   "stock": 10,                   // optional, default 0
 *   "status": 0,                   // optional, default 0 (inactive)
 *   "categories": [5, 12],         // optional, existing category ids
 *   "locales": {                   // required; the default locale's
 *                                  // name is required, other locales
 *                                  // are optional but need a name
 *                                  // when present
 *     "fr": {"name": "…", "url_key": "…", "short_description": "…",
 *            "description": "…", "meta_title": "…",
 *            "meta_description": "…", "meta_keywords": "…"},
 *     "ar": {"name": "…"},
 *     "en": {"name": "…"}
 *   },
 *   "image_urls": ["https://…/photo1.jpg"],  // optional, max 10
 *                                              // images total
 * }
 * `url_key` is optional: when missing it is generated from the name
 * (Latin names slug directly; a non-Latin name falls back to the SKU,
 * then to product-{id}), with a -2, -3, … suffix on collision. An
 * explicitly provided url_key must be a lowercase slug and unique
 * per locale. Multipart uploads use `images[]` files instead of (or
 * in addition to) `image_urls` (bmp/jpeg/jpg/png/webp, max 5 MB
 * each, max 10 images total). Images are stored the same way the
 * admin stores them (re-encoded to webp under product/{id}/).
 *
 * Duplicate guard: an existing SKU, or a name already used by
 * another product in the same locale, is rejected with 422 naming
 * the conflicting product — creating the same supplier product twice
 * is refused.
 *
 * Success (201):
 * {
 *   "created": true,
 *   "product": {"id": 512, "sku": "SUP-001", "type": "simple",
 *               "status": 0, "url": "http://localhost:8000/slug-fr"},
 *   "locales": {"fr": ["name", "url_key", "description", "price", "status"]},
 *   "stock": {"inventory_source_id": 1, "qty": 10},
 *   "categories": [5, 12],
 *   "images": 2
 * }
 * Bad payload / duplicate → 422 with field errors.
 *
 * Example:
 *   curl -X POST http://localhost:8000/api/cod/products \
 *     -H "X-COD-Product-Key: <key>" \
 *     -H "Content-Type: application/json" \
 *     -d '{"sku": "SUP-001", "price": 149, "stock": 10,
 *          "locales": {"fr": {"name": "Produit test"}} }'
 */
class CodProductCreateController extends Controller
{
    public function __invoke(Request $request, ProductApiService $service): JsonResponse
    {
        $providedKey = $request->header(ProductApiService::HEADER)
            ?? $request->bearerToken();

        if (! $service->isAuthorized($providedKey)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
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
            'images' => 'nullable|array|max:'.ProductApiService::MAX_IMAGES,
            'images.*' => 'file|mimes:bmp,jpeg,jpg,png,webp|max:5120',
        ]);

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

        $imageCount = count($request->file('images', [])) + count($validated['image_urls'] ?? []);

        if ($imageCount > ProductApiService::MAX_IMAGES) {
            $errors['images'] = ['At most '.ProductApiService::MAX_IMAGES.' images are allowed per product.'];
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $imageFiles = $service->collectImageFiles(
                $request->file('images', []),
                $validated['image_urls'] ?? []
            );
        } catch (ProductImageException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['images' => [$e->getMessage()]],
            ], 422);
        }

        $result = $service->createProduct($validated, $imageFiles);

        $product = $result['product'];
        $defaultUrlKey = $this->defaultUrlKey($service, $product, $locales, $result['locales']);

        return response()->json([
            'created' => true,
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'type' => $product->type,
                'status' => (int) ($validated['status'] ?? 0),
                'url' => $defaultUrlKey ? $service->productUrl($product, $defaultUrlKey) : null,
            ],
            'locales' => $result['locales'],
            'stock' => $result['stock'],
            'categories' => array_values($validated['categories'] ?? []),
            'images' => $result['images'],
        ], 201);
    }

    /**
     * The url_key stored for the default locale (for the response
     * URL). It was generated by the service when not provided, so
     * read it back from the database.
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
