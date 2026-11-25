<?php

namespace App\Console\Commands;

use App\Cod\Products\ProductApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jemla En Ligne — bundled mykitchenglow catalogue import.
 *
 * Imports the mykitchenglow.com products that were extracted and
 * deduped against the store on 2026-11-25 (103 Shopify products →
 * 39 clearly new + 50 uncertain, 0 confirmed duplicates, 14
 * excluded: 6 with no available variant + 8 intra-store duplicates
 * collapsed; Simo decided to import all 89). The batch payloads
 * live in the repo itself (database/seeders/data/mykitchenglow/,
 * bulk-001.json … bulk-009.json, 10 products each), so Simo runs
 * the import himself on the host via SSH at deploy time, with no
 * API calls from anywhere else.
 *
 * Names are ARABIC (mykitchenglow writes its product names in
 * Arabic, e.g. "سخان كهربائي HOFMANN 1200W"). There is no French
 * name available, so the Arabic name is written in BOTH the ar
 * and the fr locale — the create endpoint requires the name in
 * the default locale (fr on live). The FR storefront will show
 * Arabic names until the FR rewrite pass. The url_key is left to
 * the service: it is generated from the name and falls back to
 * the SKU slug when the slug would be empty.
 *
 * Every product maps to live category 3 MAISON (all 89 are kitchen
 * / household items: appliances, cookware, tea sets, trays, spice
 * organizers, wall clocks). No product was held for category and
 * no new category is needed.
 *
 * Idempotent and resumable: a product whose SKU
 * (mykitchenglow-<id>) already exists in the database is SKIPPED,
 * never created twice, so re-running the command after an
 * interrupted run (SSH drop, timeout, …) continues where it
 * stopped. A failure in one product (image download 429/timeout,
 * duplicate name, bad category, …) is recorded and does NOT stop
 * the run — the command goes on with the next product, exactly
 * like the bulk endpoint.
 *
 * Every product is created INACTIVE (status 0, stock 10) exactly
 * as the batch files say — nothing is published without Simo's
 * approval. Creation goes through
 * ProductApiService::createBulkItem(), the same per-product path
 * as POST /api/cod/products/bulk, so the command and the endpoint
 * can never drift apart.
 *
 * Full run: 89 products in 9 batches, ~6 minutes
 * (~3.6 s/product at the measured nove pace — images download
 * from cdn.shopify.com). Run it under nohup or tmux/screen so
 * a dropped SSH session does not kill it, e.g.:
 *
 *   nohup php artisan jemla:import-mykitchenglow \
 *       > storage/logs/mykitchenglow-import.log 2>&1 &
 *
 * Failures are written to
 * storage/logs/jemla-import-mykitchenglow-failures-<timestamp>.json.
 */
class ImportMyKitchenGlowCommand extends Command
{
    protected $signature = 'jemla:import-mykitchenglow
        {--limit= : Maximum number of products to process (for testing; default: all)}';

    protected $description = 'Import the bundled mykitchenglow catalogue (89 products in 9 batches, inactive, stock 10) — idempotent, resumable';

    /**
     * Bundled batch payloads, relative to the project root.
     */
    public const DATA_DIR = 'database/seeders/data/mykitchenglow';

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

        $this->info('MyKitchenGlow import — '.count($files).' batch files'.($limit !== null ? " (limit: {$limit} products)" : '').'.');
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
            $logPath = storage_path('logs/jemla-import-mykitchenglow-failures-'.date('Y-m-d-His').'.json');
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
