<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — fix every doshift product in one run: turn all five
 * settings ON, fill a missing weight, and give it a fresh random SKU.
 *
 * Why this command exists (verified live on squidlux.com, 2026-11-26):
 * doshift products show in some listings but their detail page returns
 * 404. The storefront proxy controller
 * (Webkul\Shop\Http\Controllers\ProductsCategoriesProxyController::index)
 * aborts with 404 when a product has no url_key, is not visible
 * individually, or is not active (status). Doshift products were
 * imported with status 0 and without the other settings, so their
 * pages 404 even though the url_key is correct. Verified live: the
 * Flamingo Wash Wax product 404s on its exact slug, while three other
 * doshift products that Simo activated by hand in the admin return
 * 200. The local reproduction is identical: doshift-shaped test
 * products (status 0, no flags, no weight) 404 until this command
 * runs, then return 200.
 *
 * Scope: only products whose SKU starts with `doshift-`. The ~300
 * older products keep their settings and SKUs untouched.
 *
 * What changes, per product (only where it is not already so):
 *   - status = 1 on the default channel (the 404 cause);
 *   - visible_individually = 1 (the other 404 cause);
 *   - guest_checkout = 1, so the COD guest flow is not blocked;
 *   - new = 1 and featured = 1 (Simo asked for every toggle ON,
 *     including featured — the featured section will then list the
 *     doshift products);
 *   - weight = the --weight value (default 1) only where weight is
 *     missing, empty or zero — a real weight is never overwritten;
 *   - sku = a fresh random alphanumeric string (default 10 chars),
 *     unique across the whole database (re-rolled on collision).
 *
 * Verified storage (see packages/Webkul/Installer/src/Database/
 * Seeders/Attribute/AttributeTableSeeder.php):
 *   - status: attribute boolean, value_per_channel = 1 — one row per
 *     product on the default channel. Only status is channel-scoped.
 *   - new, featured, visible_individually, guest_checkout: boolean,
 *     value_per_channel = 0, value_per_locale = 0 — one global row
 *     per product (channel NULL). A missing row counts as OFF.
 *   - weight: text (decimal), global, one row per product.
 *   - products.sku is a real column with a UNIQUE index, so a random
 *     SKU must be checked for uniqueness before saving.
 *
 * Writes go through ProductAttributeValueRepository::saveValues() —
 * the same method the admin edit form uses — never raw SQL on the
 * values table, so channel/locale scoping and the unique key are
 * respected. The SKU is written through the product model save, so
 * the product observer fires.
 *
 * Behaviour:
 *   - The five flags and the weight are only written where they are
 *     not already 1 (a real weight is never overwritten).
 *   - A re-run finds no `doshift-*` products — their SKUs are now
 *     random — so it changes nothing. To fix newly imported doshift
 *     products later, just run it again: it picks up only the new
 *     `doshift-*` SKUs and leaves the already-fixed ones alone.
 *   - The SKU is NOT idempotent by value: a product that still has
 *     a `doshift-*` SKU (e.g. a retry after a failure) gets a NEW
 *     random SKU on every run. That is expected: the point is a
 *     fresh random SKU every time it runs.
 *   - A mapping old SKU -> new SKU (with product id) is written to
 *     storage/logs/jemla-doshift-sku-mapping-<timestamp>.json, so the
 *     change is reversible and traceable. No file is written on
 *     --dry-run.
 *   - A failure on one product is logged and never aborts the run.
 *
 * WARNING: after this runs, `jemla:import-doshift` and
 * `jemla:add-doshift-images` can no longer match these products by
 * SKU — they look products up by their `doshift-<id>` SKU, which
 * will no longer exist. A re-run of either command would re-create
 * products instead of skipping them. The mapping file in
 * storage/logs is the way back (restore a SKU with a direct update,
 * or map the new SKU back to the doshift id before any re-run).
 * The same warning already applied to `jemla:randomize-skus`.
 *
 * After the run Simo must run `php artisan indexer:index
 * --mode=full` so the activated products appear in category pages,
 * storefront search and the sitemap (the command writes EAV rows
 * directly and does not rebuild the product_flat index).
 *
 * Run under nohup or tmux/screen so a dropped SSH session does
 * not kill it, e.g.:
 *
 *   nohup php artisan jemla:fix-doshift-products \
 *       > storage/logs/fix-doshift-products.log 2>&1 &
 *
 * The mapping is written to
 * storage/logs/jemla-doshift-sku-mapping-<timestamp>.json; failures
 * to storage/logs/jemla-fix-doshift-failures-<timestamp>.json.
 */
class FixDoshiftProductsCommand extends Command
{
    protected $signature = 'jemla:fix-doshift-products
        {--weight=1 : Weight to fill in where weight is missing or zero}
        {--length=10 : Length of the new random SKU (alphanumeric, minimum 4)}
        {--limit= : Maximum number of products to process (for testing; default: all doshift products)}
        {--dry-run : Show what would change without changing anything}';

    protected $description = 'Fix doshift products: all 5 settings ON, weight 1 where missing, fresh random SKU';

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

        $total = DB::table('products')->where('sku', 'like', 'doshift-%')->count();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $this->info('Fix doshift products — scope: sku LIKE doshift-%, channel: '.$channel
            .', fill weight: '.$weight.', new SKU length: '.$length
            .($limit !== null ? ", limit: {$limit} products" : '')
            .($dryRun ? ' (DRY RUN — nothing will change)' : '')
            .'.');
        $this->info('Status, visible individually, guest checkout, new and featured are set to 1 where they are not already 1; weight is filled only where it is missing, empty or zero; every product gets a fresh random SKU.');

        // Every SKU currently in use, so a generated SKU can never
        // collide with a product that has not been processed yet
        // (the unique index on products.sku would reject it).
        // Freed as each product is renamed: its old SKU leaves the
        // set, its new SKU enters it.
        $taken = DB::table('products')->pluck('sku')->flip()->all();

        $started = microtime(true);
        $processed = 0;
        $changed = 0;
        $alreadyOk = 0;
        $failed = 0;
        $flagFixed = array_fill_keys(self::FLAG_CODES, 0);
        $weightFilled = 0;
        $skuChanged = 0;
        $mapping = [];
        $failures = [];
        $samples = [];

        $bar = $this->output->createProgressBar(max($total, 1));
        $bar->start();

        DB::table('products')
            ->where('sku', 'like', 'doshift-%')
            ->orderBy('id')
            ->select(['id', 'sku'])
            ->chunkById(500, function ($rows) use (
                $limit,
                $length,
                $weight,
                $dryRun,
                $channel,
                $flagAttributes,
                $weightAttribute,
                &$taken,
                &$processed,
                &$changed,
                &$alreadyOk,
                &$failed,
                &$flagFixed,
                &$weightFilled,
                &$skuChanged,
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

                        $newSku = Str::random($length);

                        while (isset($taken[$newSku])) {
                            $newSku = Str::random($length);
                        }

                        if ($dryRun) {
                            if (empty($needsFlag) && ! $needsWeight) {
                                $alreadyOk++;
                            }

                            if (count($samples) < 5) {
                                $samples[] = [
                                    'id' => $row->id,
                                    'old_sku' => $row->sku,
                                    'new_sku' => $newSku,
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

                            $skuChanged++;
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
                            $newSku,
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

                            $product->sku = $newSku;
                            $product->save();
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

                        $savedSku = DB::table('products')
                            ->where('id', $row->id)
                            ->value('sku');

                        if ($savedSku !== $newSku) {
                            throw new \RuntimeException('SKU was not saved (still '.var_export($savedSku, true).').');
                        }

                        unset($taken[$row->sku]);
                        $taken[$newSku] = true;

                        if (empty($needsFlag) && ! $needsWeight) {
                            $alreadyOk++;
                        }

                        $mapping[] = [
                            'id' => $row->id,
                            'old_sku' => $row->sku,
                            'new_sku' => $newSku,
                        ];

                        $skuChanged++;
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
            'Done in %.1fs — processed: %d, changed: %d, flags+weight already OK: %d, failed: %d.',
            $elapsed,
            $processed,
            $changed,
            $alreadyOk,
            $failed
        ));
        $this->info('Flags set to 1: '.$flagSummary
            .'; weight filled: '.$weightFilled
            .'; SKUs changed: '.$skuChanged.'.');

        if ($dryRun) {
            if ($samples !== []) {
                $this->info('Sample changes (dry run — not saved):');

                foreach ($samples as $sample) {
                    $this->line('  #'.$sample['id'].': '.$sample['old_sku'].' -> '.$sample['new_sku']
                        .' | flags: '.implode(',', $sample['flags'])
                        .' | weight: '.($sample['weight'] ? 'fill' : 'keep'));
                }
            }
        } elseif ($mapping !== []) {
            $mappingPath = storage_path('logs/jemla-doshift-sku-mapping-'.date('Y-m-d-His').'.json');
            file_put_contents($mappingPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info(count($mapping).' SKU mapping(s) written to '.$mappingPath);
            $this->info('Keep that file — it is the way back to the old SKUs.');
        }

        if ($failures !== []) {
            $logPath = storage_path('logs/jemla-fix-doshift-failures-'.date('Y-m-d-His').'.json');
            file_put_contents($logPath, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->warn(count($failures).' failure(s) written to '.$logPath);
            $this->warn('Re-run the command to retry them (already-fixed flags and weight are skipped; SKUs are re-randomized).');
        }

        if (! $dryRun) {
            $this->info('Run `php artisan indexer:index --mode=full` so the fixed products appear in categories, search and the sitemap.');
            $this->warn('After this, jemla:import-doshift / jemla:add-doshift-images can no longer match these products by SKU.');
        }

        return self::SUCCESS;
    }
}
