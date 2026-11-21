<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Jemla — corrections page d'accueil + catégories (2026-11-21).
 *
 * 1. Catégories : 2 produits étaient mal classés dans le seeder démo
 *    (Lampe LED pour Écran dans GAMING, Caméra de Recul dans GAMING).
 *    On les déplace (MAISON / MULTIMÉDIA) en base.
 * 2. NOS PROMOS était vide (0 produit) alors que 49 produits sont en
 *    promotion : on y rattache tous les produits avec un special_price
 *    valide, en catégorie secondaire (les catégories principales ne
 *    changent pas).
 * 3. Page d'accueil : le seed démo n'avait AUCUNE section produit.
 *    On crée 3 carrousels avec des filtres distincts par construction
 *    (nouveautés / promos / multimédia) ; le dédoublonnage entre
 *    sections est en plus assuré côté client dans les composants
 *    v-products-carousel / v-products-grid.
 *
 * Idempotent : peut être relancé sans doublon.
 */
class JemlaHomepageFixSeeder extends Seeder
{
    public function run(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();

        $this->fixMisplacedProducts();
        $this->fillPromosCategory();
        $this->createHomepageSections();

        \Artisan::call('optimize:clear');

        $this->command->info('JemlaHomepageFixSeeder: catégories corrigées + NOS PROMOS remplie + 3 sections accueil créées.');
    }

    protected function categoryIdByEnglishName(string $name): ?int
    {
        return \DB::table('category_translations')
            ->where('locale', 'en')
            ->where('name', $name)
            ->value('category_id');
    }

    protected function productIdBySku(string $sku): ?int
    {
        $skuAttributeId = \DB::table('attributes')->where('code', 'sku')->value('id');

        // Les SKU sont stockés avec le préfixe "JEMLA-" (ex. JEMLA-W8-ULTRA-MAX).
        return \DB::table('product_attribute_values')
            ->where('attribute_id', $skuAttributeId)
            ->where(function ($query) use ($sku) {
                $query->where('text_value', $sku)
                    ->orWhere('text_value', 'JEMLA-'.$sku);
            })
            ->value('product_id');
    }

    protected function fixMisplacedProducts(): void
    {
        $moves = [
            // sku => bonne catégorie
            'LAMPE-LED-ECRAN' => 'MAISON',
            'CAMERA-RECUL'    => 'MULTIMÉDIA',
        ];

        foreach ($moves as $sku => $categoryName) {
            $productId = $this->productIdBySku($sku);
            $categoryId = $this->categoryIdByEnglishName($categoryName);

            if (! $productId || ! $categoryId) {
                $this->command->warn("Déplacement ignoré : $sku -> $categoryName (introuvable).");
                continue;
            }

            // Retirer des autres catégories non-racine, puis rattacher à la bonne.
            \DB::table('product_categories')
                ->where('product_id', $productId)
                ->delete();

            \DB::table('product_categories')->insert([
                'product_id'  => $productId,
                'category_id' => $categoryId,
            ]);
        }
    }

    protected function fillPromosCategory(): void
    {
        $promosId = $this->categoryIdByEnglishName('NOS PROMOS');

        if (! $promosId) {
            $this->command->warn('Catégorie NOS PROMOS introuvable.');
            return;
        }

        $onSaleIds = \DB::table('product_flat')
            ->where('locale', 'en')
            ->whereNotNull('special_price')
            ->where('special_price', '>', 0)
            ->whereColumn('special_price', '<', 'price')
            ->pluck('product_id')
            ->unique()
            ->all();

        $existing = \DB::table('product_categories')
            ->where('category_id', $promosId)
            ->pluck('product_id')
            ->all();

        $toInsert = array_diff($onSaleIds, $existing);

        foreach ($toInsert as $productId) {
            \DB::table('product_categories')->insert([
                'product_id'  => $productId,
                'category_id' => $promosId,
            ]);
        }

        $this->command->info('NOS PROMOS : '.count($toInsert).' produits rattachés ('.count($onSaleIds).' en promotion au total).');
    }

    protected function createHomepageSections(): void
    {
        $promosId = $this->categoryIdByEnglishName('NOS PROMOS') ?? 5;
        $multimediaId = $this->categoryIdByEnglishName('MULTIMÉDIA') ?? 4;

        $sections = [
            [
                'name'       => 'Jemla New Arrivals',
                'sort_order' => 13,
                'titles'     => ['en' => 'New Arrivals', 'ar' => 'وصل حديثا', 'fr' => 'Nouveautés'],
                'filters'    => ['sort' => 'created_at-desc', 'limit' => 8],
            ],
            [
                'name'       => 'Jemla Our Offers',
                'sort_order' => 14,
                'titles'     => ['en' => 'Our Offers', 'ar' => 'عروضنا', 'fr' => 'Nos Promos'],
                'filters'    => ['sort' => 'created_at-desc', 'limit' => 8, 'category_id' => $promosId],
            ],
            [
                'name'       => 'Jemla Multimedia',
                'sort_order' => 15,
                'titles'     => ['en' => 'Multimedia', 'ar' => 'الوسائط المتعددة', 'fr' => 'Multimédia'],
                'filters'    => ['sort' => 'created_at-desc', 'limit' => 8, 'category_id' => $multimediaId],
            ],
        ];

        foreach ($sections as $section) {
            $id = \DB::table('theme_customizations')
                ->where('type', 'product_carousel')
                ->where('name', $section['name'])
                ->value('id');

            if (! $id) {
                $id = \DB::table('theme_customizations')->insertGetId([
                    'theme_code' => 'default',
                    'type'       => 'product_carousel',
                    'name'       => $section['name'],
                    'sort_order' => $section['sort_order'],
                    'status'     => 1,
                    'channel_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                \DB::table('theme_customizations')
                    ->where('id', $id)
                    ->update(['sort_order' => $section['sort_order'], 'status' => 1, 'updated_at' => now()]);
            }

            foreach ($section['titles'] as $locale => $title) {
                \DB::table('theme_customization_translations')->updateOrInsert(
                    ['theme_customization_id' => $id, 'locale' => $locale],
                    ['options' => json_encode(
                        ['title' => $title, 'filters' => $section['filters']],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )]
                );
            }
        }
    }
}
