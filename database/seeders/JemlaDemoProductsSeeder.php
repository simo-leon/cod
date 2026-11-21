<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — 50 produits démo réalistes (MAD, en chiffres).
 *
 * Créés via ProductRepository (create + update), comme le fait l'admin :
 * attributs EAV, product_flat, inventaires, canaux et catégories.
 * Devise d'affichage locale : USD (seed démo) — les montants sont en dirhams.
 *
 * Usage : php artisan db:seed --class=JemlaDemoProductsSeeder
 * Idempotent : relancer le seeder supprime d'abord les produits JEMLA-*.
 */
class JemlaDemoProductsSeeder extends Seeder
{
    /** @var array<int, array{0:string,1:string,2:int,3:int|null,4:int,5:string}> name, sku suffix, price, special_price, qty, category */
    protected array $products = [
        // Produits réels du site live (prix vérifiés 2026-11-20)
        ['Montre connectée W8 Ultra Max', 'W8-ULTRA-MAX', 230, 259, 25, 'GAMING'],
        ['Écouteurs Bluetooth EB-20', 'EB-20', 110, 159, 40, 'MULTIMÉDIA'],
        ['Écouteurs Bluetooth Z78', 'Z78', 199, 249, 0, 'MULTIMÉDIA'],
        ['Souffleur d\'Air Électrique', 'SOUFFLEUR-AIR', 170, 220, 15, 'MAISON'],
        ['Lampe de Bureau LED', 'LAMPE-BUREAU', 125, 189, 30, 'MAISON'],
        ['Tondeuse HTC AT-129 Résistante à l\'Eau', 'TONDEUSE-HTC-AT129', 120, 180, 18, 'MAISON'],
        ['Mini Projecteur Intelligent HD WiFi Bluetooth', 'MINI-PROJECTEUR-HD', 759, 1099, 12, 'MULTIMÉDIA'],
        ['Récepteur Azatech Z1', 'AZATECH-Z1', 519, 799, 20, 'MULTIMÉDIA'],
        ['Enceinte Bluetooth AZATECH AZ-831BT', 'AZATECH-AZ831BT', 449, 860, 0, 'MULTIMÉDIA'],
        ['Montre Connectée WS91', 'WS91', 285, 349, 0, 'GAMING'],
        ['Bocal de Conservation Hermétique', 'BOCAL-CONSERVATION', 85, 125, 60, 'MAISON'],
        ['Lampe LED pour Écran', 'LAMPE-LED-ECRAN', 135, 179, 22, 'MAISON'],
        // Gadgets / gaming
        ['Manette de Jeu Sans Fil Pro', 'MANETTE-PRO', 249, 329, 35, 'GAMING'],
        ['Clavier Gamer RGB Mécanique', 'CLAVIER-GAMER-RGB', 349, 499, 14, 'GAMING'],
        ['Souris Gamer 7200 DPI', 'SOURIS-GAMER-7200', 149, 199, 45, 'GAMING'],
        ['Casque Gamer avec Micro', 'CASQUE-GAMER', 299, 399, 0, 'GAMING'],
        ['Tapis de Souris XXL RGB', 'TAPIS-SOURIS-XXL', 99, 149, 50, 'GAMING'],
        ['Support Ventilé pour PC Portable', 'SUPPORT-PC-VENTILE', 179, 249, 16, 'GAMING'],
        ['Webcam Full HD 1080p', 'WEBCAM-FHD-1080', 219, 299, 28, 'MULTIMÉDIA'],
        ['Microphone USB Studio', 'MICRO-USB-STUDIO', 329, 449, 10, 'MULTIMÉDIA'],
        // Montres / audio
        ['Montre Connectée T800 Ultra', 'T800-ULTRA', 199, 279, 33, 'GAMING'],
        ['Montre Connectée X9 Pro', 'X9-PRO', 259, 359, 0, 'GAMING'],
        ['Écouteurs Air Pro TWS', 'AIR-PRO-TWS', 139, 199, 55, 'MULTIMÉDIA'],
        ['Écouteurs Sport Étanches IPX7', 'ECOUTEURS-SPORT-IPX7', 159, 219, 26, 'MULTIMÉDIA'],
        ['Enceinte Portable Waterproof', 'ENCEINTE-WATERPROOF', 279, 379, 19, 'MULTIMÉDIA'],
        ['Barre de Son TV Bluetooth', 'BARRE-SON-TV', 399, 549, 8, 'MULTIMÉDIA'],
        ['Casque Bluetooth ANC', 'CASQUE-ANC', 349, 499, 0, 'MULTIMÉDIA'],
        // Mobiles / accessoires
        ['Chargeur Rapide 65W GaN', 'CHARGEUR-65W-GAN', 129, 179, 70, 'MULTIMÉDIA'],
        ['Câble USB-C Tressé 2m', 'CABLE-USB-C-2M', 49, 79, 120, 'MULTIMÉDIA'],
        ['Power Bank 20000mAh', 'POWERBANK-20000', 199, 279, 42, 'MULTIMÉDIA'],
        ['Support Téléphone Voiture Magnétique', 'SUPPORT-VOITURE-MAG', 69, 99, 80, 'MULTIMÉDIA'],
        ['Coque Transparente Universelle', 'COQUE-TRANSPARENTE', 39, 59, 150, 'MULTIMÉDIA'],
        ['Selfie Stick avec Trépied', 'SELFIE-STICK-TREPIED', 89, 129, 0, 'MULTIMÉDIA'],
        ['Mini Ventilateur USB Portable', 'VENTILATEUR-USB', 59, 89, 65, 'MAISON'],
        // Maison / pratique
        ['Aspirateur Robot Intelligent', 'ASPIRATEUR-ROBOT', 1499, 1999, 6, 'MAISON'],
        ['Friteuse à Air 5L', 'FRITEUSE-AIR-5L', 599, 799, 11, 'MAISON'],
        ['Mixeur Portable Rechargeable', 'MIXEUR-PORTABLE', 149, 199, 38, 'MAISON'],
        ['Balance de Cuisine Digitale', 'BALANCE-CUISINE', 99, 139, 44, 'MAISON'],
        ['Lampe Solaire Extérieur', 'LAMPE-SOLAIRE', 119, 169, 0, 'MAISON'],
        ['Humidificateur d\'Air Ultrasonique', 'HUMIDIFICATEUR-AIR', 179, 249, 21, 'MAISON'],
        ['Brosse Électrique Nettoyante', 'BROSSE-NETTOYANTE', 89, 129, 47, 'MAISON'],
        ['Organisateur de Câbles', 'ORGANISATEUR-CABLES', 45, 65, 90, 'MAISON'],
        ['Mini Frigo USB 4L', 'MINI-FRIGO-USB', 299, 399, 9, 'MAISON'],
        // Auto / divers
        ['Caméra de Recul Voiture', 'CAMERA-RECUL', 349, 449, 13, 'MULTIMÉDIA'],
        ['Compresseur d\'Air Portable Voiture', 'COMPRESSEUR-VOITURE', 259, 349, 17, 'MAISON'],
        ['Projecteur LED Cinéma Maison', 'PROJECTEUR-CINEMA', 899, 1299, 0, 'MULTIMÉDIA'],
        ['Drone Mini avec Caméra HD', 'DRONE-MINI-HD', 699, 899, 7, 'GAMING'],
        ['Console Rétro 400 Jeux', 'CONSOLE-RETRO-400', 329, 449, 24, 'GAMING'],
        ['Kit LED RGB Ambiance', 'KIT-LED-RGB', 109, 159, 58, 'MAISON'],
        ['Aspirateur de Table Portable', 'ASPIRATEUR-TABLE', 129, null, 29, 'MAISON'],
    ];

    protected array $categories = [
        'GAMING' => 'gaming',
        'MAISON' => 'maison',
        'MULTIMÉDIA' => 'multimedia',
        'NOS PROMOS' => 'nos-promos',
    ];

    public function run(): void
    {
        // `php artisan db:seed` exécute les seeders dans Model::unguarded() :
        // sans ça, $product->update($data) essaierait d'écrire TOUS les
        // attributs (url_key, name, price...) comme colonnes de `products`.
        \Illuminate\Database\Eloquent\Model::reguard();

        $productRepo = app(ProductRepository::class);
        $categoryRepo = app(CategoryRepository::class);

        // 1. Catégories (créées si absentes, sous Root id=1)
        // Note : CategoryRepository::create() échoue ici (colonne `locale`
        // inconnue) — on passe par la factory + traduction Astrotomic.
        $categoryIds = [];
        foreach ($this->categories as $name => $slug) {
            $existing = \DB::table('category_translations')
                ->where('slug', $slug)->where('locale', 'en')
                ->first();

            if ($existing) {
                $categoryIds[$name] = $existing->category_id;
                continue;
            }

            // Via le modèle + NestedSet (appendToNode) : la factory échoue
            // (faker null en contexte seeder) et le repository échoue sur `locale`.
            $root = \Webkul\Category\Models\Category::findOrFail(1);
            $category = new \Webkul\Category\Models\Category([
                'status' => 1,
                'position' => count($categoryIds) + 1,
                'display_mode' => 'products_and_description',
            ]);
            $category->appendToNode($root)->save();

            $translation = $category->translateOrNew('en');
            $translation->name = $name;
            $translation->slug = $slug;
            $translation->description = $name;
            $translation->locale = 'en';
            $translation->save();

            $categoryIds[$name] = $category->id;
        }

        // 2. Supprimer les anciens produits démo JEMLA (idempotent)
        $oldIds = \DB::table('products')->where('sku', 'like', 'JEMLA-%')->pluck('id');
        foreach ($oldIds as $id) {
            try {
                $productRepo->delete($id);
            } catch (\Throwable $e) {
                \DB::table('products')->where('id', $id)->delete();
            }
        }

        // 3. Créer les 50 produits
        $now = now();
        $created = 0;
        $outOfStock = 0;

        foreach ($this->products as $index => [$name, $skuSuffix, $price, $oldPrice, $qty, $category]) {
            $sku = 'JEMLA-'.$skuSuffix;
            $urlKey = Str::slug($name).'-'.strtolower($skuSuffix);
            $special = $oldPrice && $oldPrice > $price ? $price : null;
            $listPrice = $special ? $oldPrice : $price;

            $product = $productRepo->create([
                'type' => 'simple',
                'attribute_family_id' => 1,
                'sku' => $sku,
            ]);

            $productRepo->update([
                'sku' => $sku,
                'url_key' => $urlKey,
                'name' => $name,
                'short_description' => $name.' — livraison partout au Maroc, paiement à la livraison.',
                'description' => $name.'. Produit de qualité, livré en 24-48h dans les grandes villes du Maroc, paiement à la livraison après vérification du colis.',
                'price' => $listPrice,
                'special_price' => $special,
                'special_price_from' => $special ? $now->copy()->subDay()->format('Y-m-d H:i:s') : null,
                'special_price_to' => $special ? $now->copy()->addDays(30)->format('Y-m-d H:i:s') : null,
                'weight' => 1,
                'status' => 1,
                'visible_individually' => 1,
                'manage_stock' => 1,
                'new' => $index < 15 ? 1 : 0,
                'featured' => $index < 10 ? 1 : 0,
                'guest_checkout' => 1,
                'channel' => 'default',
                'locale' => 'en',
                'channels' => [1],
                'categories' => [$categoryIds[$category]],
                'inventories' => [1 => $qty],
            ], $product->id);

            // L'index flat n'est rafraîchi que par le listener
            // `catalog.product.update.after` (déclenché par le contrôleur
            // admin, pas par le repository) : on le rafraîchit ici.
            app(\Webkul\Product\Helpers\Indexers\Flat::class)->refresh($product->refresh());

            $created++;
            if ($qty === 0) {
                $outOfStock++;
            }
        }

        // Index prix/stock : le listener `catalog.product.update.after`
        // (qui déclenche ces jobs) n'est pas appelé par le repository,
        // donc sans ça getMinimalPrice() retombe sur le prix de base
        // et le special_price ne s'affiche pas.
        \Artisan::call('indexer:index', ['--mode' => 'full']);

        $this->command->info("JemlaDemoProductsSeeder: {$created} produits créés, {$outOfStock} en rupture de stock, ".count($categoryIds).' catégories.');
    }
}
