<?php

namespace App\Console\Commands;

use App\Cod\Products\ProductApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jemla En Ligne — apply the doshift translation chunks.
 *
 * Reads the translated copy produced by the doshift translation
 * pass (chunk-001.json … chunk-NNN.json, 50 products each:
 * {sku, doshift_id, locales: {ar, en, fr}}) and writes the
 * per-locale name / short_description / description /
 * meta_title / meta_description / meta_keywords onto the matching
 * products through ProductApiService::updateProduct() — the same
 * path as POST /api/cod/products/{id}, so the product_flat index
 * is refreshed by the usual update events.
 *
 * Safety rules (same as the rewrite skill):
 * - Text only. Price, categories, images, stock and status are
 *   NEVER touched — every product stays INACTIVE until Simo
 *   activates it himself.
 * - No url_key is sent, so existing slugs are left untouched.
 * - Idempotent: re-running a chunk overwrites the same values.
 * - One bad product never stops the run; failures are recorded
 *   in storage/logs/jemla-apply-translations-failures-<ts>.json.
 *
 * Finding the product:
 * - Locally (and on a fresh import) the SKU is still
 *   doshift-<id>, so the default lookup is by SKU.
 * - LIVE the SKUs were randomised by fix commands, so a plain
 *   SKU lookup finds NOTHING live. For a live run pass
 *   --map=<mapping.json> with {"doshift-<id>": <jemla_id>, …}
 *   (the mapping files live on the host under
 *   storage/logs/jemla-*-sku-mapping-*.json) and products are
 *   found by Jemla id instead. Do NOT run this live by SKU.
 *
 * Install: copy this file to app/Console/Commands/ (it is picked
 * up automatically) and bundle the chunk files under
 * database/seeders/data/doshift-translations/ so Simo can run it
 * on the host via SSH, e.g.:
 *
 *   php artisan jemla:apply-translations --chunk=chunk-001.json
 *   php artisan jemla:apply-translations --limit=10 --dry-run
 *   php artisan jemla:apply-translations --map=storage/logs/jemla-doshift-sku-mapping-2026-11-25.json
 */
class ApplyTranslationsCommand extends Command
{
    protected $signature = 'jemla:apply-translations
        {--dir= : Directory holding chunk-*.json (default: database/seeders/data/doshift-translations)} 
        {--chunk= : Apply a single chunk file only, e.g. chunk-001.json}
        {--map= : JSON mapping {"doshift-<id>": <jemla_id>} — find products by Jemla id instead of SKU (required for live runs)}
        {--limit= : Maximum number of products to process (for testing; default: all)}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Apply doshift translation chunks (ar/en/fr copy) to products — text only, never touches price, stock or status';

    public function __construct(
        protected ProductApiService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $limit = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : null;

        $dir = $this->option('dir') ?: base_path('database/seeders/data/doshift-translations');

        if (! is_dir($dir)) {
            $this->error('Translation directory not found: '.$dir);

            return self::FAILURE;
        }

        $map = $this->loadMap($this->option('map'));

        if ($map === null) {
            return self::FAILURE;
        }

        $files = $this->option('chunk')
            ? [$dir.'/'.$this->option('chunk')]
            : (glob($dir.'/chunk-*.json') ?: []);

        sort($files);

        if ($files === []) {
            $this->error('No translation chunk files (chunk-*.json) found in '.$dir);

            return self::FAILURE;
        }

        $this->info('Apply translations — '.count($files).' chunk file(s)'.($limit !== null ? " (limit: {$limit} products)" : '').($map !== [] ? ' (lookup by Jemla id via --map)' : ' (lookup by SKU)').($this->option('dry-run') ? ' [DRY RUN]' : '').'.');
        $this->info('Text only: price, categories, images, stock and status are never touched; products stay INACTIVE.');

        $started = microtime(true);
        $processed = 0;
        $updated = 0;
        $notFound = 0;
        $failed = 0;
        $failures = [];
        $stop = false;

        foreach ($files as $file) {
            if ($stop || ($limit !== null && $processed >= $limit)) {
                break;
            }

            $decoded = json_decode(file_get_contents($file), true);

            if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
                $this->error(basename($file).': invalid chunk file (expected {"products": [...]}), skipping file.');
                $failures[] = ['file' => basename($file), 'sku' => null, 'error' => 'Invalid chunk file: expected {"products": [...]}.'];
                $failed++;

                continue;
            }

            $fileUpdated = 0;
            $fileNotFound = 0;
            $fileFailed = 0;

            foreach ($decoded['products'] as $item) {
                if ($limit !== null && $processed >= $limit) {
                    $stop = true;

                    break;
                }

                $processed++;
                $sku = is_array($item) ? ($item['sku'] ?? null) : null;
                $locales = is_array($item) ? ($item['locales'] ?? null) : null;

                if (! is_string($sku) || $sku === '' || ! is_array($locales) || $locales === []) {
                    $failed++;
                    $fileFailed++;
                    $failures[] = ['file' => basename($file), 'sku' => $sku, 'error' => 'Chunk entry has no sku or no locales.'];

                    continue;
                }

                $errors = $this->service->validateLocales($locales);

                if ($errors !== []) {
                    $failed++;
                    $fileFailed++;
                    $failures[] = ['file' => basename($file), 'sku' => $sku, 'error' => 'Locale validation failed.', 'errors' => $errors];
                    $this->warn("  FAILED {$sku}: locale validation failed.");

                    continue;
                }

                $productId = $this->findProductId($sku, $map);

                if ($productId === null) {
                    $notFound++;
                    $fileNotFound++;
                    $failures[] = ['file' => basename($file), 'sku' => $sku, 'error' => $map !== [] ? 'No product with the mapped Jemla id.' : 'No product with this SKU (live SKUs were randomised — use --map).'];

                    continue;
                }

                if ($this->option('dry-run')) {
                    $updated++;
                    $fileUpdated++;

                    continue;
                }

                try {
                    $product = $this->service->findProduct($productId);

                    if ($product === null) {
                        $notFound++;
                        $fileNotFound++;
                        $failures[] = ['file' => basename($file), 'sku' => $sku, 'error' => "Product #{$productId} no longer exists."];

                        continue;
                    }

                    $this->service->updateProduct($product, $locales, null);
                    $updated++;
                    $fileUpdated++;
                } catch (\Throwable $e) {
                    $failed++;
                    $fileFailed++;
                    $failures[] = ['file' => basename($file), 'sku' => $sku, 'error' => $e->getMessage()];
                    $this->warn("  FAILED {$sku}: {$e->getMessage()}");
                }
            }

            $this->info(sprintf(
                '%s: %d updated, %d not found, %d failed',
                basename($file),
                $fileUpdated,
                $fileNotFound,
                $fileFailed
            ));
        }

        $elapsed = microtime(true) - $started;

        $this->info('');
        $this->info(sprintf(
            'Done in %.1fs — processed: %d, updated: %d, not found: %d, failed: %d.',
            $elapsed,
            $processed,
            $updated,
            $notFound,
            $failed
        ));

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-apply-translations-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' issue(s) written to '.$logPath);
        }

        return self::SUCCESS;
    }

    /**
     * Load the sku => jemla_id mapping file, or [] for SKU lookup.
     * Returns null (and prints an error) when --map was given but
     * the file is missing or invalid.
     */
    protected function loadMap(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return [];
        }

        if (! is_file($path)) {
            $this->error('Mapping file not found: '.$path);

            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('Mapping file is not valid JSON: '.$path);

            return null;
        }

        return $decoded;
    }

    /**
     * Resolve a chunk entry to a product id: by the mapped Jemla id
     * when --map is used, otherwise by SKU.
     */
    protected function findProductId(string $sku, array $map): ?int
    {
        if ($map !== []) {
            $id = $map[$sku] ?? null;

            return is_numeric($id) ? (int) $id : null;
        }

        $id = DB::table('products')->where('sku', $sku)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
