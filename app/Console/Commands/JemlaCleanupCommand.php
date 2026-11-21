<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jemla En Ligne — data cleanup & audit for the live database.
 *
 * Dry-run by default: only reports what would change.
 * Use --apply to actually write the fixes.
 *
 * 1. Squidlux: replaces squidlux.com / Squidlux references in core_config,
 *    CMS page translations, theme customizations and channel translations
 *    with jemlaenligne.com / Jemla En Ligne, and fixes the footer copyright.
 * 2. Guest checkout: enables sales.checkout.shopping_cart.allow_guest_checkout
 *    and sets the guest_checkout product attribute to 1 for every product.
 * 3. Prices: reports products whose special_price is invalid
 *    (special_price >= price, or <= 0) with the recomputed discount %.
 * 4. Homepage: reports theme customizations (product carousels / grids)
 *    that repeat the exact same filters.
 *
 * Run on a copy of the live database first, never directly on production
 * without a backup.
 */
class JemlaCleanupCommand extends Command
{
    protected $signature = 'jemla:cleanup {--apply : Write the fixes to the database (default is dry-run)}';

    protected $description = 'Audit and fix Jemla En Ligne live data: Squidlux references, guest COD checkout, prices, homepage duplicates';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'Mode: APPLY (les corrections seront écrites)' : 'Mode: DRY-RUN (aucune écriture, ajoutez --apply pour appliquer)');

        $this->cleanupSquidlux($apply);
        $this->enableGuestCheckout($apply);
        $this->auditPrices();
        $this->auditHomepage();

        return self::SUCCESS;
    }

    /**
     * Replace Squidlux references in text columns.
     */
    protected function cleanupSquidlux(bool $apply): void
    {
        $this->info('');
        $this->info('--- 1. Squidlux ---');

        // table => [id column, text columns...]
        $targets = [
            'core_config' => ['id', ['value']],
            'cms_page_translations' => ['id', ['page_title', 'html_content', 'meta_title', 'meta_description', 'meta_keywords']],
            'theme_customization_translations' => ['id', ['options']],
            'channel_translations' => ['id', ['name', 'home_seo']],
            'category_translations' => ['id', ['name', 'description']],
        ];

        $total = 0;

        foreach ($targets as $table => [$idColumn, $columns]) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $existingColumns = $this->tableColumns($table);

            foreach ($columns as $column) {
                if (! in_array($column, $existingColumns, true)) {
                    continue;
                }

                $rows = DB::table($table)
                    ->where($column, 'like', '%squid%')
                    ->get([$idColumn, $column]);

                foreach ($rows as $row) {
                    $total++;
                    $fixed = $this->replaceSquidlux((string) $row->{$column});
                    $this->line("  [{$table}#{$row->{$idColumn}}] {$column}: ".mb_substr((string) $row->{$column}, 0, 80));

                    if ($apply && $fixed !== (string) $row->{$column}) {
                        DB::table($table)->where($idColumn, $row->{$idColumn})->update([$column => $fixed]);
                    }
                }
            }
        }

        // Footer copyright
        $copyright = DB::table('core_config')->where('code', 'general.content.footer.copyright_content')->first();

        if (! $copyright || stripos((string) $copyright->value, 'squid') !== false || stripos((string) $copyright->value, 'jemla') === false) {
            $total++;
            $this->line('  [core_config] general.content.footer.copyright_content: '.($copyright ? (string) $copyright->value : '(manquant)'));

            if ($apply) {
                $text = '© '.date('Y').' Jemla En Ligne. All rights reserved.';

                if ($copyright) {
                    DB::table('core_config')->where('id', $copyright->id)->update(['value' => $text, 'locale_code' => 'en']);
                } else {
                    DB::table('core_config')->insert([
                        'code' => 'general.content.footer.copyright_content',
                        'value' => $text,
                        'locale_code' => 'en', // locale_based=true: sans locale, le shop ne le voit pas
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->info($total === 0 ? '  Aucune référence Squidlux trouvée.' : "  {$total} référence(s) Squidlux / copyright trouvée(s).");
    }

    protected function replaceSquidlux(string $text): string
    {
        $text = str_ireplace('squidlux.com', 'jemlaenligne.com', $text);
        $text = str_ireplace('Squidlux', 'Jemla En Ligne', $text);
        $text = str_ireplace('squidlux', 'jemlaenligne', $text);

        return $text;
    }

    /**
     * Enable guest checkout globally and per product.
     */
    protected function enableGuestCheckout(bool $apply): void
    {
        $this->info('');
        $this->info('--- 2. Guest checkout (COD) ---');

        $config = DB::table('core_config')->where('code', 'sales.checkout.shopping_cart.allow_guest_checkout')->first();
        $current = $config ? (string) $config->value : '(manquant)';
        $this->line("  sales.checkout.shopping_cart.allow_guest_checkout = {$current}");

        if ($apply && $current !== '1') {
            if ($config) {
                DB::table('core_config')->where('id', $config->id)->update(['value' => '1']);
            } else {
                DB::table('core_config')->insert([
                    'code' => 'sales.checkout.shopping_cart.allow_guest_checkout',
                    'value' => '1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->info('  -> mis à 1.');
        }

        $attribute = DB::table('attributes')->where('code', 'guest_checkout')->first();

        if (! $attribute) {
            $this->warn('  Attribut guest_checkout introuvable.');

            return;
        }

        $notGuest = DB::table('product_attribute_values')
            ->where('attribute_id', $attribute->id)
            ->where(function ($query) {
                $query->whereNull('boolean_value')->orWhere('boolean_value', 0);
            })
            ->count();

        $missing = DB::table('products')
            ->whereNotExists(function ($query) use ($attribute) {
                $query->select(DB::raw(1))->from('product_attribute_values')
                    ->whereColumn('product_attribute_values.product_id', 'products.id')
                    ->where('product_attribute_values.attribute_id', $attribute->id);
            })
            ->count();

        $this->line("  Produits avec guest_checkout = 0 / null : {$notGuest}");
        $this->line("  Produits sans valeur guest_checkout : {$missing}");

        if ($apply && ($notGuest > 0 || $missing > 0)) {
            DB::table('product_attribute_values')
                ->where('attribute_id', $attribute->id)
                ->where(function ($query) {
                    $query->whereNull('boolean_value')->orWhere('boolean_value', 0);
                })
                ->update(['boolean_value' => 1]);

            $this->info("  -> {$notGuest} produit(s) mis à jour.");
        }
    }

    /**
     * Report products with an invalid special price (report only, never auto-fixed).
     */
    protected function auditPrices(): void
    {
        $this->info('');
        $this->info('--- 3. Prix / réductions (rapport seulement) ---');

        $priceAttr = DB::table('attributes')->where('code', 'price')->first();
        $specialAttr = DB::table('attributes')->where('code', 'special_price')->first();

        if (! $priceAttr || ! $specialAttr) {
            $this->warn('  Attributs price / special_price introuvables.');

            return;
        }

        $rows = DB::table('products as p')
            ->join('product_attribute_values as pv', function ($join) use ($priceAttr) {
                $join->on('pv.product_id', '=', 'p.id')->where('pv.attribute_id', $priceAttr->id);
            })
            ->join('product_attribute_values as sv', function ($join) use ($specialAttr) {
                $join->on('sv.product_id', '=', 'p.id')->where('sv.attribute_id', $specialAttr->id);
            })
            ->whereNotNull('sv.float_value')
            ->select('p.id', 'p.sku', 'pv.float_value as price', 'sv.float_value as special_price')
            ->get();

        $bad = 0;

        foreach ($rows as $row) {
            $price = (float) $row->price;
            $special = (float) $row->special_price;

            if ($price <= 0 || $special <= 0 || $special >= $price) {
                $bad++;
                $this->line("  [produit #{$row->id} sku={$row->sku}] price={$price} special_price={$special} -> INVALIDE");
                continue;
            }

            $discount = round(($price - $special) * 100 / $price, 2);
            $this->line("  [produit #{$row->id} sku={$row->sku}] price={$price} special_price={$special} réduction réelle={$discount}%");
        }

        $this->info($rows->isEmpty() ? '  Aucun produit avec special_price.' : "  {$rows->count()} produit(s) avec special_price, dont {$bad} invalide(s).");
    }

    /**
     * Report homepage customizations that repeat the same product filters.
     */
    protected function auditHomepage(): void
    {
        $this->info('');
        $this->info('--- 4. Page d\'accueil (doublons) ---');

        if (! $this->tableExists('theme_customizations') || ! $this->tableExists('theme_customization_translations')) {
            $this->warn('  Tables theme_customizations introuvables.');

            return;
        }

        $rows = DB::table('theme_customizations as c')
            ->join('theme_customization_translations as t', 't.theme_customization_id', '=', 'c.id')
            ->whereIn('c.type', ['product_carousel', 'product_grid'])
            ->where('c.status', 1)
            ->select('c.id', 'c.type', 'c.name', 't.options')
            ->orderBy('c.sort_order')
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $options = json_decode((string) $row->options, true);
            $filters = json_encode($options['filters'] ?? $options);
            $key = $row->type.'|'.$filters;

            $flag = isset($seen[$key]) ? ' -> DOUBLON de #'.$seen[$key] : '';
            $seen[$key] = $row->id;

            $this->line("  [#{$row->id}] {$row->type} '{$row->name}' filtres={$filters}{$flag}");
        }

        $this->info($rows->isEmpty() ? '  Aucun carrousel / grille produit actif.' : "  {$rows->count()} section(s) produit sur la page d'accueil.");
    }

    protected function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    /** @return string[] */
    protected function tableColumns(string $table): array
    {
        return DB::getSchemaBuilder()->getColumnListing($table);
    }
}
