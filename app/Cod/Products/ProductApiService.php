<?php

namespace App\Cod\Products;

use App\Cod\Products\Exceptions\ProductImageException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Inventory\Repositories\InventorySourceRepository;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Product\Repositories\ProductInventoryRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Shared domain logic of the COD product API.
 *
 * Endpoints (see routes/api.php and the Api controllers):
 *   POST /api/cod/products       — create a simple product (inactive
 *                                  by default, nothing is published
 *                                  without Simo's approval).
 *   POST /api/cod/products/{id}  — update one product's per-locale
 *                                  content and/or stock.
 *
 * How Bagisto stores product content (there is no product_translations
 * table): every translatable field is an EAV attribute value in
 * `product_attribute_values`, keyed by product + attribute + channel +
 * locale. This service writes through
 * ProductAttributeValueRepository::saveValues() — the same method the
 * admin edit form uses — so only the fields actually provided are
 * touched and nothing else (price, categories, images, …) is wiped.
 * After the writes it dispatches the same `catalog.product.*` events
 * the admin controllers dispatch, so the product_flat index is
 * refreshed synchronously by the Product listener.
 *
 * Auth: one secret key, stored in the admin settings
 * (general.cod_system.settings.product_secret_key, Admin →
 * Configuration → General → COD System → Product Secret Key), sent as
 * the X-COD-Product-Key header or a Bearer token — the same approach
 * as the COD orders import key. The key is never hard-coded and never
 * logged; generate/rotate it with `php artisan
 * cod:generate-product-key`.
 */
class ProductApiService
{
    /**
     * Admin config code of the product API secret key.
     */
    public const CONFIG_KEY = 'general.cod_system.settings.product_secret_key';

    /**
     * Request header carrying the secret key.
     */
    public const HEADER = 'X-COD-Product-Key';

    /**
     * Per-locale fields the API may write. Price, categories, images
     * and status are handled separately (create only) and are never
     * touched by the update endpoint.
     */
    public const LOCALE_FIELDS = [
        'name',
        'url_key',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    /**
     * Extra attribute codes written on create (price is global,
     * status is per channel).
     */
    public const CREATE_EXTRA_FIELDS = ['price', 'status'];

    /**
     * Slugs the storefront reserves (same list as the admin's
     * ProductCategoryUniqueSlug rule).
     */
    public const RESERVED_SLUGS = ['categories'];

    /**
     * Image limits for the create endpoint.
     */
    public const MAX_IMAGES = 10;

    public const MAX_IMAGE_BYTES = 5242880; // 5 MB

    public function __construct(
        protected ProductRepository $products,
        protected ProductAttributeValueRepository $attributeValues,
        protected ProductInventoryRepository $inventories,
        protected ProductImageRepository $images,
        protected AttributeRepository $attributes,
        protected AttributeFamilyRepository $families,
        protected InventorySourceRepository $sources,
    ) {}

    /**
     * Secret key of the product API, from the admin settings.
     */
    public function getSecretKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY);

        return $key ? (string) $key : null;
    }

    /**
     * Constant-time check of the key the caller provided (header or
     * Bearer token). False when no key is configured.
     */
    public function isAuthorized(?string $provided): bool
    {
        $configured = $this->getSecretKey();

        return ! empty($configured)
            && ! empty($provided)
            && hash_equals($configured, $provided);
    }

    /**
     * Find a product by id, or null when it does not exist.
     */
    public function findProduct(int $id): ?Product
    {
        try {
            return $this->products->find($id);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * Locale codes configured in the store (fr, ar, en, …).
     */
    public function allowedLocaleCodes(): array
    {
        return core()->getAllLocales()->pluck('code')->all();
    }

    /**
     * Default locale of the default channel (the locale whose name is
     * required when creating a product).
     */
    public function defaultLocaleCode(): string
    {
        return core()->getDefaultLocaleCodeFromDefaultChannel();
    }

    /**
     * Default channel code (content is written for this channel).
     */
    public function defaultChannelCode(): ?string
    {
        return core()->getDefaultChannelCode();
    }

    /**
     * The EAV attributes the API may write, by code.
     */
    public function editableAttributes(): Collection
    {
        return $this->attributes->findWhereIn(
            'code',
            array_merge(self::LOCALE_FIELDS, self::CREATE_EXTRA_FIELDS)
        );
    }

    /**
     * Validate the `locales` payload shared by both endpoints.
     *
     * Returns errors keyed like `locales.fr.url_key` (empty array =
     * valid). Checks: unknown locale codes, empty locale blocks, and
     * url_key collisions with another product in the same locale.
     * Name duplication is checked separately on create
     * (findDuplicateName) and is not enforced on update.
     */
    public function validateLocales(array $locales, ?int $excludeProductId = null): array
    {
        $errors = [];
        $allowed = $this->allowedLocaleCodes();

        foreach ($locales as $locale => $fields) {
            if (! in_array($locale, $allowed, true)) {
                $errors["locales.{$locale}"] = ["Unknown locale '{$locale}'. Allowed: ".implode(', ', $allowed).'.'];

                continue;
            }

            if (! is_array($fields)) {
                $errors["locales.{$locale}"] = ['Each locale must be an object of fields.'];

                continue;
            }

            $provided = array_intersect_key($fields, array_flip(self::LOCALE_FIELDS));

            if (empty($provided)) {
                $errors["locales.{$locale}"] = ['Provide at least one field (name, url_key, short_description, description, meta_title, meta_description, meta_keywords).'];

                continue;
            }

            if (
                isset($fields['url_key'])
                && $fields['url_key'] !== ''
                && $this->isUrlKeyTaken($fields['url_key'], $locale, $excludeProductId)
            ) {
                $errors["locales.{$locale}.url_key"] = ["The url_key '{$fields['url_key']}' is already used by another product in locale '{$locale}'."];
            }
        }

        return $errors;
    }

    /**
     * Is this url_key already used by another product in this locale?
     * url_key is a per-locale attribute (value_per_channel = 0), so
     * the check is scoped by locale only. Reserved slugs are treated
     * as taken.
     */
    public function isUrlKeyTaken(string $slug, string $locale, ?int $excludeProductId = null): bool
    {
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return true;
        }

        $attribute = $this->attributes->findOneByField('code', 'url_key');

        if (! $attribute) {
            return false;
        }

        return DB::table('product_attribute_values')
            ->where('attribute_id', $attribute->id)
            ->where('locale', $locale)
            ->where('text_value', $slug)
            ->when($excludeProductId, fn ($query) => $query->where('product_id', '!=', $excludeProductId))
            ->exists();
    }

    /**
     * Find a product that already uses this name in this locale
     * (the duplicate guard for the create endpoint). Returns
     * ['id' => …, 'sku' => …] or null.
     */
    public function findDuplicateName(string $name, string $locale, ?int $excludeProductId = null): ?array
    {
        $attribute = $this->attributes->findOneByField('code', 'name');

        if (! $attribute) {
            return null;
        }

        $row = DB::table('product_attribute_values as pav')
            ->join('products', 'products.id', '=', 'pav.product_id')
            ->where('pav.attribute_id', $attribute->id)
            ->where('pav.locale', $locale)
            ->where('pav.text_value', $name)
            ->when($excludeProductId, fn ($query) => $query->where('pav.product_id', '!=', $excludeProductId))
            ->select('products.id', 'products.sku')
            ->first();

        return $row ? ['id' => $row->id, 'sku' => $row->sku] : null;
    }

    /**
     * Resolve the url_key to store for a locale: the provided one when
     * given, otherwise a slug of the name. Non-Latin names slug to an
     * empty string, so fall back to the SKU, then to product-{id}.
     * Collisions get a -2, -3, … suffix.
     */
    public function resolveUrlKey(?string $provided, string $name, string $locale, Product $product): string
    {
        if ($provided !== null && $provided !== '') {
            return $provided;
        }

        $base = Str::slug($name);

        if ($base === '') {
            $base = Str::slug($product->sku);
        }

        if ($base === '') {
            $base = 'product-'.$product->id;
        }

        $slug = $base;
        $suffix = 2;

        while ($this->isUrlKeyTaken($slug, $locale, $product->id)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Update one product's per-locale content and/or stock. Only the
     * fields present in $locales are written; anything not sent is
     * left untouched. Returns the changed fields, e.g.
     * ['locales' => ['fr' => ['name']], 'stock' => [...]].
     */
    public function updateProduct(Product $product, array $locales, ?int $stock): array
    {
        $updated = [];

        Event::dispatch('catalog.product.update.before', $product->id);

        DB::transaction(function () use ($product, $locales, $stock, &$updated) {
            $updated['locales'] = $this->saveLocales($product, $locales);

            if ($stock !== null) {
                $updated['stock'] = $this->saveStock($product, $stock);
            }
        });

        $product->refresh();

        Event::dispatch('catalog.product.update.after', $product);

        return array_filter($updated);
    }

    /**
     * Create a simple product from validated data. The product is
     * created inactive (status 0) unless $data['status'] says
     * otherwise, so nothing is published without approval. Returns
     * the product plus the changed-fields map.
     *
     * $data: sku, price, stock (optional), status (optional),
     * categories (optional ids), locales (per-locale fields).
     * $imageFiles: already downloaded/validated UploadedFile objects
     * (see collectImageFiles) — stored after the product row exists.
     */
    public function createProduct(array $data, array $imageFiles = []): array
    {
        Event::dispatch('catalog.product.create.before');

        $result = DB::transaction(function () use ($data, $imageFiles) {
            $product = $this->products->create([
                'type' => 'simple',
                'attribute_family_id' => $this->defaultAttributeFamilyId(),
                'sku' => $data['sku'],
            ]);

            $updatedLocales = $this->saveLocales(
                $product,
                $data['locales'],
                [
                    'price' => $data['price'],
                    'status' => (int) ($data['status'] ?? 0),
                ],
                true
            );

            if (! empty($data['categories'])) {
                $product->categories()->sync($data['categories']);
            }

            $stock = $this->saveStock($product, (int) ($data['stock'] ?? 0));

            $imageCount = 0;

            if (! empty($imageFiles)) {
                $this->images->upload(
                    ['images' => ['files' => $imageFiles]],
                    $product,
                    'images'
                );

                $imageCount = count($imageFiles);
            }

            return [
                'product' => $product,
                'locales' => $updatedLocales,
                'stock' => $stock,
                'images' => $imageCount,
            ];
        });

        $product = $result['product']->refresh();

        Event::dispatch('catalog.product.create.after', $product);
        Event::dispatch('catalog.product.update.after', $product);

        $result['product'] = $product;

        return $result;
    }

    /**
     * Write the per-locale fields of $locales through saveValues().
     * $extra (price/status on create) is merged into the default
     * locale's data only. When $generateUrlKeys is true (create),
     * a missing url_key is generated from the name. Returns the
     * written field names per locale.
     */
    protected function saveLocales(Product $product, array $locales, array $extra = [], bool $generateUrlKeys = false): array
    {
        $written = [];
        $attributes = $this->editableAttributes();
        $channel = $this->defaultChannelCode();
        $defaultLocale = $this->defaultLocaleCode();

        foreach ($locales as $locale => $fields) {
            $fields = array_intersect_key($fields, array_flip(self::LOCALE_FIELDS));

            if ($generateUrlKeys && ! empty($fields['name']) && empty($fields['url_key'])) {
                $fields['url_key'] = $this->resolveUrlKey(null, $fields['name'], $locale, $product);
            }

            if (empty($fields) && empty($extra)) {
                continue;
            }

            $data = array_merge($fields, [
                'locale' => $locale,
                'channel' => $channel,
            ]);

            if ($locale === $defaultLocale && ! empty($extra)) {
                $data = array_merge($data, $extra);
            }

            // saveValues() reads the product's loaded attribute_values
            // collection: reload it so rows written for a previous
            // locale are seen, never inserted twice.
            $product->refresh();

            $this->attributeValues->saveValues($data, $product, $attributes);

            $written[$locale] = array_values(array_unique(array_merge(
                array_keys($fields),
                $locale === $defaultLocale ? array_keys($extra) : []
            )));
        }

        return $written;
    }

    /**
     * Set the stock quantity on the product's inventory source: the
     * source the product already stocks on, otherwise the store's
     * default source. Returns the source id and qty.
     */
    public function saveStock(Product $product, int $qty): array
    {
        $sourceId = $this->resolveInventorySourceId($product);

        $this->inventories->saveInventories([
            'inventories' => [$sourceId => $qty],
        ], $product);

        return ['inventory_source_id' => $sourceId, 'qty' => $qty];
    }

    /**
     * Inventory source a stock write targets: the product's existing
     * source when it has exactly one, otherwise the default source.
     */
    public function resolveInventorySourceId(Product $product): int
    {
        $existing = DB::table('product_inventories')
            ->where('product_id', $product->id)
            ->orderBy('inventory_source_id')
            ->pluck('inventory_source_id');

        if ($existing->count() === 1) {
            return (int) $existing->first();
        }

        $default = $this->sources->findByField('code', 'default')->first();

        if ($default) {
            return (int) $default->id;
        }

        $first = $this->sources->all()->sortBy('id')->first();

        if (! $first) {
            throw new \RuntimeException('No inventory source is configured.');
        }

        return (int) $first->id;
    }

    /**
     * Default attribute family (code "default") for new products.
     */
    public function defaultAttributeFamilyId(): int
    {
        $family = $this->families->findOneByField('code', 'default')
            ?? $this->families->all()->sortBy('id')->first();

        if (! $family) {
            throw new \RuntimeException('No attribute family is configured.');
        }

        return (int) $family->id;
    }

    /**
     * Turn the request's images into usable UploadedFile objects:
     * uploaded files are validated as readable images, remote URLs
     * are downloaded first (fail fast, before anything is created).
     *
     * @param  UploadedFile[]  $uploaded
     * @param  string[]  $urls
     * @return UploadedFile[]
     *
     * @throws ProductImageException
     */
    public function collectImageFiles(array $uploaded, array $urls): array
    {
        if (count($uploaded) + count($urls) > self::MAX_IMAGES) {
            throw new ProductImageException('At most '.self::MAX_IMAGES.' images are allowed per product.');
        }

        $files = [];

        foreach ($uploaded as $file) {
            $this->assertReadableImage($file);
            $files[] = $file;
        }

        foreach ($urls as $url) {
            $files[] = $this->downloadImage($url);
        }

        return $files;
    }

    /**
     * Download one image URL into a temporary UploadedFile.
     *
     * @throws ProductImageException
     */
    protected function downloadImage(string $url): UploadedFile
    {
        // M30: the URL comes from an API caller — never fetch a
        // private / internal address (SSRF: 169.254.169.254, localhost,
        // the LAN, Tailscale). Only http(s) URLs whose host resolves
        // to public IPs are fetched, and redirects are followed
        // manually so a redirect to an internal address is refused too.
        $currentUrl = $url;
        $response = null;

        for ($hop = 0; $hop < 4; $hop++) {
            if (! $this->isPublicImageUrl($currentUrl)) {
                throw new ProductImageException("URL {$url} is not allowed: only public http(s) image URLs may be downloaded.");
            }

            try {
                $response = Http::timeout(20)->withoutRedirecting()->get($currentUrl);
            } catch (\Throwable $e) {
                throw new ProductImageException("Could not download image from {$url}: {$e->getMessage()}");
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true) && $response->header('Location')) {
                $location = $response->header('Location');

                // A relative redirect resolves against the current URL.
                if (! str_starts_with(strtolower($location), 'http')) {
                    $parts = parse_url($currentUrl);
                    $location = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$location;
                }

                $currentUrl = $location;

                continue;
            }

            break;
        }

        if ($response === null || in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            throw new ProductImageException("Could not download image from {$url}: too many redirects.");
        }

        if (! $response->successful()) {
            throw new ProductImageException("Could not download image from {$url} (HTTP {$response->status()}).");
        }

        $contentType = strtolower($response->header('Content-Type', ''));

        if (! str_starts_with($contentType, 'image/')) {
            throw new ProductImageException("URL {$url} did not return an image (Content-Type: {$contentType}).");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_IMAGE_BYTES) {
            throw new ProductImageException("Image from {$url} exceeds the 5 MB limit.");
        }

        $extension = match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            default => strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) ?: 'jpg',
        };

        $tmpPath = tempnam(sys_get_temp_dir(), 'cod-product-');

        file_put_contents($tmpPath, $body);

        $file = new UploadedFile($tmpPath, 'image-'.Str::random(8).'.'.$extension, $contentType, null, true);

        $this->assertReadableImage($file);

        return $file;
    }

    /**
     * Whether an image URL is safe to fetch (M30): http(s) only, and
     * every IP the host resolves to must be public — private,
     * loopback, link-local and reserved ranges are refused, so the
     * URL cannot reach the metadata service, localhost or the LAN.
     * A host that does not resolve is refused too.
     */
    protected function isPublicImageUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return false;
        }

        $ips = gethostbynamel($host);

        if ($ips === false || $ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * The file must be a readable image (same check Bagisto's media
     * upload performs when it re-encodes to webp).
     *
     * @throws ProductImageException
     */
    protected function assertReadableImage(UploadedFile $file): void
    {
        try {
            image_manager()->read($file);
        } catch (\Throwable) {
            throw new ProductImageException("File '{$file->getClientOriginalName()}' is not a readable image.");
        }
    }

    /**
     * Public storefront URL of a product from its default-locale
     * url_key.
     */
    public function productUrl(Product $product, string $urlKey): string
    {
        return url('/'.$urlKey);
    }
}
