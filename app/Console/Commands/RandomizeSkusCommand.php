<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — replace every product's SKU with a random
 * alphanumeric string (default 10 characters).
 *
 * Simo asked for this explicitly and accepted the trade-off: he
 * knows all the products and just wants the SKUs changed.
 *
 * Verified storage (see packages/Webkul/Product/src/Database/
 * Migrations/2018_07_27_065727_create_products_table.php):
 *   - `products.sku` is a real column with a UNIQUE index
 *     (`$table->string('sku')->unique()`), so a random SKU must be
 *     checked for uniqueness before saving — the command keeps a
 *     set of every SKU in use and re-rolls on collision.
 *   - Nothing else uses `products.sku` as a live key. The other
 *     tables that store a `sku` column are snapshots or derived
 *     data and must NOT be rewritten:
 *       * `product_flat.sku` — denormalized copy, rebuilt from
 *         `products.sku` by the flat indexer
 *         (packages/Webkul/Product/src/Helpers/Indexers/Flat.php
 *         line 152: `'sku' => $product->sku`). Run
 *         `php artisan indexer:index --mode=full` after this
 *         command so the flat index picks up the new SKUs.
 *       * `order_items` / `shipment_items` / `invoice_items` /
 *         `refund_items` `.sku` — historical snapshots of what
 *         was ordered/shipped/invoiced/refunded. Changing them
 *         would rewrite order history; they are left alone.
 *       * `cart_items.sku` — transient carts only. Carts expire
 *         on their own; the command does not touch them.
 *
 * Behaviour:
 *   - EVERY product gets a new random SKU, even one whose current
 *     SKU already looks 10 characters long.
 *   - Only `products.sku` changes (via the model save, so the
 *     Product observer fires). No name, price, status, weight,
 *     category, stock, image or url_key is touched.
 *   - Uniqueness is guaranteed against the whole database and
 *     against SKUs already generated in this run (re-roll on
 *     collision).
 *   - A mapping old SKU -> new SKU (with product id) is written
 *     to storage/logs/jemla-sku-mapping-<timestamp>.json, so the
 *     change is reversible and traceable. No file is written on
 *     --dry-run.
 *   - A re-run generates NEW random SKUs again — this is NOT
 *     idempotent by value. That is expected: the point of the
 *     command is a fresh random SKU every time it runs.
 *   - A failure on one product is logged and never aborts the
 *     run.
 *
 * WARNING: after this runs, `jemla:import-doshift` and
 * `jemla:add-doshift-images` can no longer match existing
 * products by SKU — they look products up by their
 * `doshift-<id>` SKU, which will no longer exist. A re-run of
 * either command would re-create products instead of skipping
 * them. The mapping file in storage/logs is the way back
 * (restore a SKU with a direct update, or map the new SKU back
 * to the doshift id before any re-run).
 *
 * After the run Simo must run `php artisan indexer:index
 * --mode=full` so product_flat (and therefore categories,
 * search and the storefront) shows the new SKUs.
 *
 * Run under nohup or tmux/screen so a dropped SSH session does
 * not kill it, e.g.:
 *
 *   nohup php artisan jemla:randomize-skus \
 *       > storage/logs/randomize-skus.log 2>&1 &
 *
 * The mapping is written to
 * storage/logs/jemla-sku-mapping-<timestamp>.json; failures to
 * storage/logs/jemla-randomize-skus-failures-<timestamp>.json.
 */
class RandomizeSkusCommand extends Command
{
    protected $signature = 'jemla:randomize-skus
        {--length=10 : Length of the new random SKU (alphanumeric, minimum 4)}
        {--limit= : Maximum number of products to process (for testing; default: all)}
        {--dry-run : Show what would change without changing anything}';

    protected $description = 'Replace every product SKU with a random alphanumeric string (default 10 chars)';

    public function __construct(
        protected ProductRepository $products,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $lengthOption = (string) $this->option('length');

        if (! ctype_digit($lengthOption) || (int) $lengthOption < 4) {
            $this->error('--length must be a positive integer of at least 4 (got: '.var_export($this->option('length'), true).').');

            return self::FAILURE;
        }

        $length = (int) $lengthOption;

        $limit = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : null;

        $dryRun = (bool) $this->option('dry-run');

        $total = DB::table('products')->count();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $this->info('Randomize all SKUs — length: '.$length
            .($limit !== null ? ", limit: {$limit} products" : '')
            .($dryRun ? ' (DRY RUN — nothing will change)' : '')
            .'.');
        $this->info('Every product gets a fresh random alphanumeric SKU; nothing else changes. A re-run generates NEW random SKUs again.');

        // Every SKU currently in use, so a generated SKU can never
        // collide with a product that has not been processed yet
        // (the unique index on products.sku would reject it).
        // Freed as each product is renamed: its old SKU leaves the
        // set, its new SKU enters it.
        $taken = DB::table('products')->pluck('sku')->flip()->all();

        $started = microtime(true);
        $processed = 0;
        $changed = 0;
        $skipped = 0;
        $failed = 0;
        $mapping = [];
        $failures = [];
        $samples = [];

        $bar = $this->output->createProgressBar(max($total, 1));
        $bar->start();

        DB::table('products')
            ->orderBy('id')
            ->select(['id', 'sku'])
            ->chunkById(500, function ($rows) use (
                $limit,
                $length,
                $dryRun,
                &$taken,
                &$processed,
                &$changed,
                &$skipped,
                &$failed,
                &$mapping,
                &$failures,
                &$samples,
                $bar,
            ) {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $bar->advance();

                    try {
                        $newSku = Str::random($length);

                        while (isset($taken[$newSku])) {
                            $newSku = Str::random($length);
                        }

                        if ($dryRun) {
                            if (count($samples) < 5) {
                                $samples[] = [
                                    'id' => $row->id,
                                    'old_sku' => $row->sku,
                                    'new_sku' => $newSku,
                                ];
                            }

                            $changed++;

                            continue;
                        }

                        $product = $this->products->find($row->id);

                        if (! $product) {
                            throw new \RuntimeException('Product no longer exists.');
                        }

                        $product->sku = $newSku;
                        $product->save();

                        // Read back from the database — count a change
                        // only when it is actually there.
                        $savedSku = DB::table('products')
                            ->where('id', $row->id)
                            ->value('sku');

                        if ($savedSku !== $newSku) {
                            throw new \RuntimeException('SKU was not saved (still '.var_export($savedSku, true).').');
                        }

                        unset($taken[$row->sku]);
                        $taken[$newSku] = true;

                        $mapping[] = [
                            'id' => $row->id,
                            'old_sku' => $row->sku,
                            'new_sku' => $newSku,
                        ];

                        $changed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $failures[] = [
                            'id' => $row->id,
                            'sku' => $row->sku,
                            'error' => $e->getMessage(),
                        ];
                        $this->warn("  FAILED #{$row->id} ({$row->sku}): {$e->getMessage()}");
                    }
                }
            });

        $bar->finish();
        $this->info('');

        $elapsed = microtime(true) - $started;

        $this->info(sprintf(
            'Done in %.1fs — processed: %d, changed: %d, skipped: %d, failed: %d.',
            $elapsed,
            $processed,
            $changed,
            $skipped,
            $failed
        ));

        if ($dryRun) {
            if ($samples !== []) {
                $this->info('Sample new SKUs (dry run — not saved):');

                foreach ($samples as $sample) {
                    $this->line("  #{$sample['id']}: {$sample['old_sku']} -> {$sample['new_sku']}");
                }
            }
        } elseif ($mapping !== []) {
            $mappingPath = storage_path('logs/jemla-sku-mapping-'.date('Y-m-d-His').'.json');
            file_put_contents($mappingPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info(count($mapping).' SKU mapping(s) written to '.$mappingPath);
            $this->info('Keep that file — it is the way back to the old SKUs.');
        }

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-randomize-skus-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' failure(s) written to '.$logPath);
        }

        if (! $dryRun) {
            $this->info('Run `php artisan indexer:index --mode=full` so product_flat picks up the new SKUs.');
            $this->warn('After this, jemla:import-doshift / jemla:add-doshift-images can no longer match existing products by SKU.');
        }

        return self::SUCCESS;
    }
}
