<?php

namespace App\Console\Commands;

use App\Cod\Products\ProductApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jemla En Ligne — bundled doshift catalogue import.
 *
 * Imports the doshift products that were extracted and deduped
 * against the store on 2026-11-25 (3,734 doshift products →
 * 1,488 clearly new + 2,075 uncertain, 158 confirmed duplicates
 * excluded; Simo decided to import all 3,563). The batch payloads
 * live in the repo itself (database/seeders/data/doshift/,
 * bulk-001.json … bulk-334.json, 10 products each, French copy
 * only — AR/EN + the selling rewrite is a later pass via the
 * per-product update endpoint, before activation), so Simo runs
 * the import himself on the host via SSH at deploy time, with no
 * API calls from anywhere else.
 *
 * Held out of the bundles (not imported): the Jouets-only products
 * and the products with no mappable category — they need new live
 * categories first (see ~/workspace/doshift-products/
 * jouets-awaiting-category.json and needs-category-review.json).
 *
 * Idempotent and resumable: a product whose SKU (doshift-<id>)
 * already exists in the database is SKIPPED, never created twice,
 * so re-running the command after an interrupted run (SSH drop,
 * timeout, …) continues where it stopped. A failure in one product
 * (image download 429/timeout, duplicate name, bad category, …)
 * is recorded and does NOT stop the run — the command goes on
 * with the next product, exactly like the bulk endpoint.
 *
 * Every product is created INACTIVE (status 0, stock 10) exactly
 * as the batch files say — nothing is published without Simo's
 * approval. Creation goes through
 * ProductApiService::createBulkItem(), the same per-product path
 * as POST /api/cod/products/bulk, so the command and the endpoint
 * can never drift apart.
 *
 * Full run: 3,337 products in 334 batches, ~40 minutes (images
 * download from doshift.ma). Run it under nohup or tmux/screen so
 * a dropped SSH session does not kill it, e.g.:
 *
 *   nohup php artisan jemla:import-doshift \
 *       > storage/logs/doshift-import.log 2>&1 &
 *
 * Failures are written to
 * storage/logs/jemla-import-doshift-failures-<timestamp>.json.
 */
class ImportDoshiftCommand extends Command
{
    protected $signature = 'jemla:import-doshift
        {--limit= : Maximum number of products to process (for testing; default: all)}';

    protected $description = 'Import the bundled doshift catalogue (3,337 products in 334 batches, inactive, stock 10) — idempotent, resumable';

    /**
     * Bundled batch payloads, relative to the project root.
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
            $this->error('Batch data directory not found: '.$dir);

            return self::FAILURE;
        }

        $files = glob($dir.'/bulk-*.json') ?: [];
        sort($files);

        if ($files === []) {
            $this->error('No batch files (bulk-*.json) found in '.$dir);

            return self::FAILURE;
        }

        $this->info('Doshift import — '.count($files).' batch files'.($limit !== null ? " (limit: {$limit} products)" : '').'.');
        $this->info('Products stay INACTIVE (status 0, stock 10) until Simo activates them. Existing SKUs are skipped; failures are logged, never abort the run.');

        $started = microtime(true);
        $processed = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];
        $stop = false;

        foreach ($files as $file) {
            if ($stop || ($limit !== null && $processed >= $limit)) {
                break;
            }

            $batchStarted = microtime(true);
            $batchCreated = 0;
            $batchSkipped = 0;
            $batchFailed = 0;

            $decoded = json_decode(file_get_contents($file), true);

            if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
                $this->error(basename($file).': invalid batch file (expected {"products": [...]}), skipping file.');
                $failures[] = [
                    'file' => basename($file),
                    'sku' => null,
                    'error' => 'Invalid batch file: expected {"products": [...]}.',
                ];
                $failed++;

                continue;
            }

            foreach ($decoded['products'] as $item) {
                if ($limit !== null && $processed >= $limit) {
                    $stop = true;

                    break;
                }

                $processed++;
                $sku = is_array($item) ? ($item['sku'] ?? null) : null;

                try {
                    if (is_string($sku) && $sku !== '' && $this->skuExists($sku)) {
                        $skipped++;
                        $batchSkipped++;

                        continue;
                    }

                    $result = $this->service->createBulkItem($item);

                    if ($result['created']) {
                        $created++;
                        $batchCreated++;
                    } else {
                        $failed++;
                        $batchFailed++;
                        $failures[] = [
                            'file' => basename($file),
                            'sku' => $result['sku'],
                            'error' => $result['payload']['error'] ?? 'Unknown error.',
                            'errors' => $result['payload']['errors'] ?? [],
                        ];
                        $this->warn("  FAILED {$result['sku']}: ".($result['payload']['error'] ?? 'Unknown error.'));
                    }
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
                '%s: %d created, %d skipped, %d failed (%.1fs)',
                basename($file),
                $batchCreated,
                $batchSkipped,
                $batchFailed,
                microtime(true) - $batchStarted
            ));
        }

        $elapsed = microtime(true) - $started;

        $this->info('');
        $this->info(sprintf(
            'Done in %.1fs — processed: %d, created: %d, skipped (already imported): %d, failed: %d.',
            $elapsed,
            $processed,
            $created,
            $skipped,
            $failed
        ));

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-import-doshift-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' failure(s) written to '.$logPath);
            $this->warn('Re-run the command to retry them (already-created SKUs are skipped).');
        }

        return self::SUCCESS;
    }

    /**
     * Whether a product with this SKU already exists — the resume
     * check. A re-run after an interrupted import skips everything
     * that is already in the database.
     */
    protected function skuExists(string $sku): bool
    {
        return DB::table('products')->where('sku', $sku)->exists();
    }
}
