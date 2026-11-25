<?php

namespace App\Console\Commands;

use App\Cod\Products\ProductApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jemla En Ligne — doshift images backfill.
 *
 * The bundled doshift import (`jemla:import-doshift`) stored only the
 * FIRST image of each product — the builder deliberately took
 * images[0] (the zunyo precedent, where every product had a single
 * image). Most doshift products have 2–8 images, so 9,575 extra
 * images are missing from the store. This command adds them.
 *
 * The backfill payloads live in the repo itself
 * (database/seeders/data/doshift/, images-backfill-001.json …
 * images-backfill-035.json, 100 products each, one entry per
 * product that has more than its first image: SKU doshift-<id> +
 * the image URLs beyond the first, deduped, at most 9 extra so no
 * product ever exceeds the API's 10-image cap). Run it AFTER the
 * import finishes, on the host via SSH at deploy time:
 *
 *   php artisan jemla:add-doshift-images
 *
 * Run it under nohup or tmux/screen so a dropped SSH session does
 * not kill it, e.g.:
 *
 *   nohup php artisan jemla:add-doshift-images \
 *       > storage/logs/doshift-images.log 2>&1 &
 *
 * Idempotent and resumable: images are matched by content hash
 * (the store keeps no source URL), so a re-run skips every image
 * that is already attached and adds only the missing ones; a
 * product whose SKU does not exist in the database (its import
 * failed, e.g. duplicate name) is recorded and skipped, never
 * aborts the run. A single image URL that fails to download (HTTP
 * 429 from doshift.ma, timeout, …) is recorded and does NOT stop
 * the product's other images or the run.
 *
 * Appending goes through ProductApiService::appendImages(), the
 * same path as POST /api/cod/products/{id} with `image_urls`, so
 * the command and the endpoint can never drift apart. Appending
 * NEVER removes an existing image and never touches name, price,
 * category, stock or status.
 *
 * Full run: 3,466 products, 9,575 images in 35 files. Failures
 * are written to
 * storage/logs/jemla-add-doshift-images-failures-<timestamp>.json.
 */
class AddDoshiftImagesCommand extends Command
{
    protected $signature = 'jemla:add-doshift-images
        {--limit= : Maximum number of products to process (for testing; default: all)}';

    protected $description = 'Append the missing doshift images (3,466 products, 9,575 images) — idempotent, resumable';

    /**
     * Bundled backfill payloads, relative to the project root.
     */
    public const DATA_DIR = 'database/seeders/data/doshift';

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

        $dir = base_path(self::DATA_DIR);

        if (! is_dir($dir)) {
            $this->error('Backfill data directory not found: '.$dir);

            return self::FAILURE;
        }

        $files = glob($dir.'/images-backfill-*.json') ?: [];
        sort($files);

        if ($files === []) {
            $this->error('No backfill files (images-backfill-*.json) found in '.$dir);

            return self::FAILURE;
        }

        $this->info('Doshift images backfill — '.count($files).' backfill files'.($limit !== null ? " (limit: {$limit} products)" : '').'.');
        $this->info('Images are appended only — nothing existing is removed. Missing SKUs are skipped; failures are logged, never abort the run.');

        $started = microtime(true);
        $processed = 0;
        $withAdded = 0;
        $added = 0;
        $alreadyAttached = 0;
        $skippedMissing = 0;
        $failed = 0;
        $failures = [];
        $stop = false;

        foreach ($files as $file) {
            if ($stop || ($limit !== null && $processed >= $limit)) {
                break;
            }

            $batchStarted = microtime(true);
            $batchAdded = 0;
            $batchSkipped = 0;
            $batchFailed = 0;

            $decoded = json_decode(file_get_contents($file), true);

            if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
                $this->error(basename($file).': invalid backfill file (expected {"products": [...]}), skipping file.');
                $failures[] = [
                    'file' => basename($file),
                    'sku' => null,
                    'error' => 'Invalid backfill file: expected {"products": [...]}.',
                ];
                $failed++;

                continue;
            }

            foreach ($decoded['products'] as $entry) {
                if ($limit !== null && $processed >= $limit) {
                    $stop = true;

                    break;
                }

                $processed++;
                $sku = is_array($entry) ? ($entry['sku'] ?? null) : null;
                $imageUrls = is_array($entry) ? ($entry['image_urls'] ?? []) : [];

                try {
                    $productId = is_string($sku) && $sku !== ''
                        ? DB::table('products')->where('sku', $sku)->value('id')
                        : null;

                    if (! $productId) {
                        $skippedMissing++;
                        $batchSkipped++;
                        $failures[] = [
                            'file' => basename($file),
                            'sku' => $sku,
                            'error' => 'Product not found in the store (SKU was never imported).',
                        ];
                        $this->warn("  SKIPPED {$sku}: product not found in the store.");

                        continue;
                    }

                    $product = $this->service->findProduct((int) $productId);

                    if (! $product) {
                        $skippedMissing++;
                        $batchSkipped++;
                        $failures[] = [
                            'file' => basename($file),
                            'sku' => $sku,
                            'error' => 'Product not found in the store (SKU was never imported).',
                        ];
                        $this->warn("  SKIPPED {$sku}: product not found in the store.");

                        continue;
                    }

                    $result = $this->service->appendImages($product, $imageUrls, true);

                    $added += $result['added'];
                    $batchAdded += $result['added'];
                    $alreadyAttached += $result['skipped_already_attached'];

                    if ($result['added'] > 0) {
                        $withAdded++;
                    }

                    foreach ($result['failed'] as $imageFailure) {
                        $failed++;
                        $batchFailed++;
                        $failures[] = [
                            'file' => basename($file),
                            'sku' => $sku,
                            'error' => $imageFailure['error'],
                            'url' => $imageFailure['url'],
                        ];
                        $this->warn("  FAILED {$sku}: {$imageFailure['error']}");
                    }

                    $this->info(sprintf(
                        '  %s: +%d image(s), %d already attached, %d failed (total %d)',
                        $sku,
                        $result['added'],
                        $result['skipped_already_attached'],
                        count($result['failed']),
                        $result['total_images']
                    ));
                } catch (\Throwable $e) {
                    $failed++;
                    $batchFailed++;
                    $failures[] = [
                        'file' => basename($file),
                        'sku' => $sku,
                        'error' => $e->getMessage(),
                    ];
                    $this->warn("  FAILED {$sku}: {$e->getMessage()}");
                }
            }

            $this->info(sprintf(
                '%s: %d image(s) added, %d skipped, %d failed (%.1fs)',
                basename($file),
                $batchAdded,
                $batchSkipped,
                $batchFailed,
                microtime(true) - $batchStarted
            ));
        }

        $elapsed = microtime(true) - $started;

        $this->info('');
        $this->info(sprintf(
            'Done in %.1fs — processed: %d products, %d got new images, %d image(s) added, %d already attached, %d skipped (missing SKU), %d failed.',
            $elapsed,
            $processed,
            $withAdded,
            $added,
            $alreadyAttached,
            $skippedMissing,
            $failed
        ));

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-add-doshift-images-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' failure(s) written to '.$logPath);
            $this->warn('Re-run the command to retry them (already-attached images are skipped).');
        }

        return self::SUCCESS;
    }
}
