<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — activate every product and fill missing weights.
 *
 * Simo asked for "a SQL script to activate everything and add
 * weight". Raw SQL is wrong here: Bagisto stores `status` and
 * `weight` as EAV attributes in `product_attribute_values`, not as
 * columns on `products` — a direct UPDATE on `products` would do
 * nothing (there is no status/weight column there) and a hand-made
 * UPDATE on the values table would have to reproduce the channel /
 * locale scoping and the unique (channel, locale, attribute_id,
 * product_id) key by hand. This command writes through
 * ProductAttributeValueRepository::saveValues() instead — the same
 * method the admin edit form and ProductApiService use — so only
 * the two intended fields are touched and nothing else (name,
 * price, category, stock, images, url_key, …) changes.
 *
 * Verified storage (see packages/Webkul/Installer/src/Database/
 * Seeders/Attribute/AttributeTableSeeder.php):
 *   - `status`: attribute id 8, type `boolean` → column
 *     `boolean_value`, value_per_channel = 1, value_per_locale = 0.
 *     One row per product, channel = the store's default channel,
 *     locale = NULL. 1 = active, 0 = inactive.
 *   - `weight`: attribute id 22, type `text` (validation decimal)
 *     → column `text_value`, value_per_channel = 0,
 *     value_per_locale = 0. One row per product, channel = NULL,
 *     locale = NULL, value = the decimal as a string.
 *
 * Behaviour:
 *   - status = 1 for every product whose status on the default
 *     channel is not already 1 (missing row counts as inactive).
 *   - weight = the --weight value (default 1) only where weight is
 *     missing, empty or zero — a real weight is never overwritten.
 *   - Nothing else changes. A re-run changes 0 products
 *     (idempotent, resumable). A failure on one product is logged
 *     and never aborts the run.
 *
 * After the run Simo must run `php artisan indexer:index
 * --mode=full` so the activated products appear in category pages
 * and storefront search (the command writes EAV rows directly and
 * does not rebuild the product_flat index).
 *
 * Run under nohup or tmux/screen so a dropped SSH session does
 * not kill it, e.g.:
 *
 *   nohup php artisan jemla:activate-all-products \
 *       > storage/logs/activate-all-products.log 2>&1 &
 *
 * Failures are written to
 * storage/logs/jemla-activate-all-failures-<timestamp>.json.
 */
class ActivateAllProductsCommand extends Command
{
    protected $signature = 'jemla:activate-all-products
        {--weight=1 : Weight to fill in where weight is missing or zero}
        {--limit= : Maximum number of products to process (for testing; default: all)}';

    protected $description = 'Activate every product (status 1) and fill missing weight — idempotent, resumable';

    public function __construct(
        protected ProductRepository $products,
        protected ProductAttributeValueRepository $attributeValues,
        protected AttributeRepository $attributes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $weight = $this->option('weight');

        if (! is_numeric($weight) || (float) $weight <= 0) {
            $this->error('--weight must be a number greater than 0 (got: '.var_export($weight, true).').');

            return self::FAILURE;
        }

        $weight = (string) $weight;

        $limit = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : null;

        $statusAttribute = $this->attributes->findOneByField('code', 'status');
        $weightAttribute = $this->attributes->findOneByField('code', 'weight');

        if (! $statusAttribute || ! $weightAttribute) {
            $this->error('Could not find the status/weight attributes (codes "status" and "weight").');

            return self::FAILURE;
        }

        $channel = core()->getDefaultChannelCode();

        if (! $channel) {
            $this->error('Could not determine the default channel code.');

            return self::FAILURE;
        }

        $statusColumn = $statusAttribute->column_name;
        $weightColumn = $weightAttribute->column_name;

        $total = DB::table('products')->count();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $this->info("Activate all products — channel: {$channel}, fill weight: {$weight}".($limit !== null ? " (limit: {$limit} products)" : '').'.');
        $this->info('Status is set to 1 where it is not already 1; weight is filled only where it is missing, empty or zero. Nothing else changes.');

        $started = microtime(true);
        $processed = 0;
        $activated = 0;
        $weightFilled = 0;
        $alreadyActive = 0;
        $alreadyWeighted = 0;
        $failed = 0;
        $failures = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DB::table('products')
            ->orderBy('id')
            ->select(['id', 'sku'])
            ->chunkById(500, function ($rows) use (
                $limit,
                $statusAttribute,
                $weightAttribute,
                $statusColumn,
                $weightColumn,
                $channel,
                $weight,
                &$processed,
                &$activated,
                &$weightFilled,
                &$alreadyActive,
                &$alreadyWeighted,
                &$failed,
                &$failures,
                $bar,
            ) {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $bar->advance();

                    try {
                        $statusValue = DB::table('product_attribute_values')
                            ->where('product_id', $row->id)
                            ->where('attribute_id', $statusAttribute->id)
                            ->where('channel', $channel)
                            ->value($statusColumn);

                        $needsActivation = (int) $statusValue !== 1;

                        $weightValue = DB::table('product_attribute_values')
                            ->where('product_id', $row->id)
                            ->where('attribute_id', $weightAttribute->id)
                            ->value($weightColumn);

                        $needsWeight = $weightValue === null
                            || trim((string) $weightValue) === ''
                            || (float) $weightValue == 0.0;

                        if (! $needsActivation) {
                            $alreadyActive++;
                        }

                        if (! $needsWeight) {
                            $alreadyWeighted++;
                        }

                        if (! $needsActivation && ! $needsWeight) {
                            continue;
                        }

                        $product = $this->products->find($row->id);

                        DB::transaction(function () use (
                            $product,
                            $statusAttribute,
                            $weightAttribute,
                            $channel,
                            $weight,
                            $needsActivation,
                            $needsWeight,
                        ) {
                            if ($needsActivation) {
                                // saveValues() reads the product's loaded
                                // attribute_values collection: reload it so
                                // rows written just before are seen, never
                                // inserted twice.
                                $product->refresh();

                                $this->attributeValues->saveValues(
                                    ['status' => 1, 'channel' => $channel],
                                    $product,
                                    collect([$statusAttribute])
                                );
                            }

                            if ($needsWeight) {
                                $product->refresh();

                                $this->attributeValues->saveValues(
                                    ['weight' => $weight, 'channel' => $channel],
                                    $product,
                                    collect([$weightAttribute])
                                );
                            }
                        });

                        // Read back from the database — count a change
                        // only when it is actually there.
                        if ($needsActivation) {
                            $newStatus = DB::table('product_attribute_values')
                                ->where('product_id', $row->id)
                                ->where('attribute_id', $statusAttribute->id)
                                ->where('channel', $channel)
                                ->value($statusColumn);

                            if ((int) $newStatus !== 1) {
                                throw new \RuntimeException('Status was not set to 1 (still '.var_export($newStatus, true).').');
                            }

                            $activated++;
                        }

                        if ($needsWeight) {
                            $newWeight = DB::table('product_attribute_values')
                                ->where('product_id', $row->id)
                                ->where('attribute_id', $weightAttribute->id)
                                ->value($weightColumn);

                            if ($newWeight === null || (float) $newWeight == 0.0) {
                                throw new \RuntimeException('Weight was not filled (still '.var_export($newWeight, true).').');
                            }

                            $weightFilled++;
                        }
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
            'Done in %.1fs — processed: %d, activated: %d, weight filled: %d, already active: %d, already with weight: %d, failed: %d.',
            $elapsed,
            $processed,
            $activated,
            $weightFilled,
            $alreadyActive,
            $alreadyWeighted,
            $failed
        ));

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-activate-all-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' failure(s) written to '.$logPath);
            $this->warn('Re-run the command to retry them (already-activated products are skipped).');
        }

        $this->info('Run `php artisan indexer:index --mode=full` so the activated products appear in categories and search.');

        return self::SUCCESS;
    }
}
