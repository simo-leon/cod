<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — fix EVERY product in one run: turn all five
 * settings ON and fill a missing weight. The SKU is NEVER touched.
 *
 * This is the jemlaenligne.com counterpart of
 * `jemla:fix-doshift-products` (built for squidlux.com, 2026-11-26):
 * same five flags, same weight rule, but the scope is every product
 * in the database — no SKU filter — and the SKU is left exactly as
 * it is. Simo asked for this on 2026-11-26 16:22: jemlaenligne.com
 * already has random SKUs, and he wants all products fixed the same
 * way squidlux's doshift products were.
 *
 * Why the pages 404 (verified on squidlux.com, 2026-11-26): the
 * storefront proxy controller
 * (Webkul\Shop\Http\Controllers\ProductsCategoriesProxyController::index)
 * aborts with 404 when a product has no url_key, is not visible
 * individually, or is not active (status). Products imported with
 * status 0 and without the other settings 404 even though the
 * url_key is correct. The local reproduction is identical:
 * test products in the bad state 404 until this command runs,
 * then return 200.
 *
 * What changes, per product (only where it is not already so):
 *   - status = 1 on the default channel (the 404 cause);
 *   - visible_individually = 1 (the other 404 cause);
 *   - guest_checkout = 1, so the COD guest flow is not blocked;
 *   - new = 1 and featured = 1 (Simo asked for every toggle ON —
 *     the "new" and "featured" sections will then list every
 *     product; the --dry-run counts show the size before running);
 *   - weight = the --weight value (default 1) only where weight is
 *     missing, empty or zero — a real weight is never overwritten.
 *
 * What NEVER changes:
 *   - sku — jemlaenligne.com already has random SKUs, and the
 *     import commands (`jemla:import-doshift`,
 *     `jemla:add-doshift-images`) match products by their
 *     `doshift-<id>` SKU. Changing a SKU would break that matching
 *     and a re-run of an import would re-create the product
 *     instead of skipping it. That is exactly why the squidlux
 *     command re-randomized SKUs and why this one must not.
 *   - name, price, category, stock, images, url_key, … — nothing
 *     else is written.
 *
 * Verified storage (see packages/Webkul/Installer/src/Database/
 * Seeders/Attribute/AttributeTableSeeder.php):
 *   - status: attribute boolean, value_per_channel = 1 — one row per
 *     product on the default channel. Only status is channel-scoped.
 *   - new, featured, visible_individually, guest_checkout: boolean,
 *     value_per_channel = 0, value_per_locale = 0 — one global row
 *     per product (channel NULL). A missing row counts as OFF.
 *   - weight: text (decimal), global, one row per product.
 *
 * Writes go through ProductAttributeValueRepository::saveValues() —
 * the same method the admin edit form uses — never raw SQL on the
 * values table, so channel/locale scoping and the unique key are
 * respected.
 *
 * Behaviour:
 *   - The five flags and the weight are only written where they are
 *     not already 1 (a real weight is never overwritten).
 *   - Idempotent and resumable: a re-run changes 0 products.
 *   - A failure on one product is logged and never aborts the run.
 *   - A run summary (counters + failures) is written to
 *     storage/logs/jemla-fix-all-products-<timestamp>.json, so the
 *     run is traceable. On --dry-run the same file is written with
 *     "dry_run": true and nothing is changed.
 *
 * After the run Simo must run `php artisan indexer:index
 * --mode=full` so the activated products appear in category pages,
 * storefront search and the sitemap (the command writes EAV rows
 * directly and does not rebuild the product_flat index).
 *
 * Run under nohup or tmux/screen so a dropped SSH session does
 * not kill it, e.g.:
 *
 *   nohup php artisan jemla:fix-all-products \
 *       > storage/logs/fix-all-products.log 2>&1 &
 */
class FixAllProductsCommand extends Command
{
    protected $signature = 'jemla:fix-all-products
        {--weight=1 : Weight to fill in where weight is missing or zero}
        {--limit= : Maximum number of products to process (for testing; default: all products)}
        {--dry-run : Show what would change without changing anything}';

    protected $description = 'Fix all products: all 5 settings ON, weight 1 where missing, SKU untouched';

    /** Attribute codes this command sets to 1, in write order. */
    protected const FLAG_CODES = [
        'status',
        'visible_individually',
        'guest_checkout',
        'new',
        'featured',
    ];

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

        $dryRun = (bool) $this->option('dry-run');

        $channel = core()->getDefaultChannelCode();

        if (! $channel) {
            $this->error('Could not determine the default channel code.');

            return self::FAILURE;
        }

        $flagAttributes = [];
        $weightAttribute = $this->attributes->findOneByField('code', 'weight');

        foreach (self::FLAG_CODES as $code) {
            $attribute = $this->attributes->findOneByField('code', $code);

            if (! $attribute) {
                $this->error('Could not find the "'.$code.'" attribute.');

                return self::FAILURE;
            }

            $flagAttributes[$code] = $attribute;
        }

        if (! $weightAttribute) {
            $this->error('Could not find the "weight" attribute.');

            return self::FAILURE;
        }

        $total = DB::table('products')->count();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $this->info('Fix all products — scope: every product, channel: '.$channel
            .', fill weight: '.$weight
            .($limit !== null ? ", limit: {$limit} products" : '')
            .($dryRun ? ' (DRY RUN — nothing will change)' : '')
            .'.');
        $this->info('Status, visible individually, guest checkout, new and featured are set to 1 where they are not already 1; weight is filled only where it is missing, empty or zero; SKUs are never touched.');

        $started = microtime(true);
        $processed = 0;
        $changed = 0;
        $alreadyOk = 0;
        $failed = 0;
        $flagFixed = array_fill_keys(self::FLAG_CODES, 0);
        $weightFilled = 0;
        $failures = [];
        $samples = [];

        $bar = $this->output->createProgressBar(max($total, 1));
        $bar->start();

        DB::table('products')
            ->orderBy('id')
            ->select(['id', 'sku'])
            ->chunkById(500, function ($rows) use (
                $limit,
                $weight,
                $dryRun,
                $channel,
                $flagAttributes,
                $weightAttribute,
                &$processed,
                &$changed,
                &$alreadyOk,
                &$failed,
                &$flagFixed,
                &$weightFilled,
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
                        // Read the current values. Status is
                        // channel-scoped; the other flags and the
                        // weight are global (channel NULL). A missing
                        // row counts as OFF / missing.
                        $needsFlag = [];

                        foreach ($flagAttributes as $code => $attribute) {
                            $query = DB::table('product_attribute_values')
                                ->where('product_id', $row->id)
                                ->where('attribute_id', $attribute->id);

                            if ($code === 'status') {
                                $query->where('channel', $channel);
                            }

                            $value = $query->value($attribute->column_name);

                            if ((int) $value !== 1) {
                                $needsFlag[$code] = $attribute;
                            }
                        }

                        $weightValue = DB::table('product_attribute_values')
                            ->where('product_id', $row->id)
                            ->where('attribute_id', $weightAttribute->id)
                            ->value($weightAttribute->column_name);

                        $needsWeight = $weightValue === null
                            || trim((string) $weightValue) === ''
                            || (float) $weightValue == 0.0;

                        $needsAnything = ! empty($needsFlag) || $needsWeight;

                        if (! $needsAnything) {
                            $alreadyOk++;

                            continue;
                        }

                        if ($dryRun) {
                            if (count($samples) < 5) {
                                $samples[] = [
                                    'id' => $row->id,
                                    'sku' => $row->sku,
                                    'flags' => array_keys($needsFlag),
                                    'weight' => $needsWeight,
                                ];
                            }

                            foreach (array_keys($needsFlag) as $code) {
                                $flagFixed[$code]++;
                            }

                            if ($needsWeight) {
                                $weightFilled++;
                            }

                            $changed++;

                            continue;
                        }

                        $product = $this->products->find($row->id);

                        if (! $product) {
                            throw new \RuntimeException('Product no longer exists.');
                        }

                        DB::transaction(function () use (
                            $product,
                            $needsFlag,
                            $needsWeight,
                            $weight,
                            $weightAttribute,
                            $channel,
                        ) {
                            foreach ($needsFlag as $code => $attribute) {
                                // saveValues() reads the product's
                                // loaded attribute_values collection:
                                // reload it so rows written just
                                // before are seen, never inserted
                                // twice.
                                $product->refresh();

                                $this->attributeValues->saveValues(
                                    [$code => 1, 'channel' => $channel],
                                    $product,
                                    collect([$attribute])
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
                        foreach ($needsFlag as $code => $attribute) {
                            $query = DB::table('product_attribute_values')
                                ->where('product_id', $row->id)
                                ->where('attribute_id', $attribute->id);

                            if ($code === 'status') {
                                $query->where('channel', $channel);
                            }

                            $saved = $query->value($attribute->column_name);

                            if ((int) $saved !== 1) {
                                throw new \RuntimeException('Flag "'.$code.'" was not set to 1 (still '.var_export($saved, true).').');
                            }

                            $flagFixed[$code]++;
                        }

                        if ($needsWeight) {
                            $savedWeight = DB::table('product_attribute_values')
                                ->where('product_id', $row->id)
                                ->where('attribute_id', $weightAttribute->id)
                                ->value($weightAttribute->column_name);

                            if ($savedWeight === null || (float) $savedWeight == 0.0) {
                                throw new \RuntimeException('Weight was not filled (still '.var_export($savedWeight, true).').');
                            }

                            $weightFilled++;
                        }

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

        $flagSummary = implode(', ', array_map(
            fn ($code) => $code.'='.$flagFixed[$code],
            self::FLAG_CODES
        ));

        $this->info(sprintf(
            'Done in %.1fs — processed: %d, changed: %d, already OK: %d, failed: %d.',
            $elapsed,
            $processed,
            $changed,
            $alreadyOk,
            $failed
        ));
        $this->info('Flags set to 1: '.$flagSummary.'; weight filled: '.$weightFilled.'.');

        if ($dryRun && $samples !== []) {
            $this->info('Sample changes (dry run — not saved):');

            foreach ($samples as $sample) {
                $this->line('  #'.$sample['id'].' ('.$sample['sku'].')'
                    .' | flags: '.implode(',', $sample['flags'])
                    .' | weight: '.($sample['weight'] ? 'fill' : 'keep'));
            }
        }

        $summary = [
            'command' => 'jemla:fix-all-products',
            'dry_run' => $dryRun,
            'channel' => $channel,
            'weight' => $weight,
            'limit' => $limit,
            'elapsed_seconds' => round($elapsed, 1),
            'processed' => $processed,
            'changed' => $changed,
            'already_ok' => $alreadyOk,
            'failed' => $failed,
            'flags_set' => $flagFixed,
            'weight_filled' => $weightFilled,
            'failures' => $failures,
        ];

        $summaryPath = storage_path('logs/jemla-fix-all-products-'.date('Y-m-d-His').'.json');
        file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Run summary written to '.$summaryPath);

        if ($failures !== []) {
            $this->warn('Re-run the command to retry them (already-fixed flags and weight are skipped).');
        }

        if (! $dryRun) {
            $this->info('Run `php artisan indexer:index --mode=full` so the fixed products appear in categories, search and the sitemap.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
