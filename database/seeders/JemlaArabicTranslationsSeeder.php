<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Webkul\Category\Models\Category;
use Webkul\CMS\Models\Page;
use Webkul\Product\Helpers\Indexers\Flat;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Jemla En Ligne — traduction arabe + française (locale ar/fr, devise MAD).
 *
 * Ce que fait ce seeder (idempotent, relançable) :
 *  - crée les locales `ar` (rtl) et `fr` (ltr), les rattache au canal
 *    `default` et met `ar` comme locale par défaut du canal (FR reste
 *    disponible via le sélecteur de langue du header).
 *  - crée la devise MAD (symbole DH), la met comme devise de base du
 *    canal et retire USD du sélecteur (USD reste dans la table
 *    `currencies`, sans taux de change MAD fiable à ce jour).
 *  - traduit les 4 catégories, les 50 produits JEMLA-* (noms/descriptions
 *    AR + FR), les titres des 10 pages CMS démo, les liens du footer,
 *    les services et le bandeau d'offre, et ajoute le copyright du
 *    footer en ar/fr (le bug locale_code du cleanup est pris en compte).
 *
 * Les textes d'interface (header, panier, checkout...) sont déjà traduits
 * en arabe dans le repo : packages/Webkul/Shop/src/Resources/lang/ar/app.php
 * (1332 clés, 0 manquante vs en). Ce seeder ne touche qu'aux DONNÉES.
 *
 * Usage : php artisan db:seed --class=JemlaArabicTranslationsSeeder
 */
class JemlaArabicTranslationsSeeder extends Seeder
{
    /** @var array<string,string> suffixe SKU => nom arabe */
    protected array $arabicNames = [
        'W8-ULTRA-MAX' => 'ساعة ذكية W8 ألترا ماكس',
        'EB-20' => 'سماعات بلوتوث EB-20',
        'Z78' => 'سماعات بلوتوث Z78',
        'SOUFFLEUR-AIR' => 'منفاخ هواء كهربائي',
        'LAMPE-BUREAU' => 'مصباح مكتب LED',
        'TONDEUSE-HTC-AT129' => 'ماكينة حلاقة HTC AT-129 مقاومة للماء',
        'MINI-PROJECTEUR-HD' => 'بروجيكتور صغير ذكي HD واي فاي بلوتوث',
        'AZATECH-Z1' => 'جهاز استقبال Azatech Z1',
        'AZATECH-AZ831BT' => 'مكبر صوت بلوتوث AZATECH AZ-831BT',
        'WS91' => 'ساعة ذكية WS91',
        'BOCAL-CONSERVATION' => 'علبة حفظ محكمة الإغلاق',
        'LAMPE-LED-ECRAN' => 'مصباح LED للشاشة',
        'MANETTE-PRO' => 'يد تحكم لاسلكية برو للألعاب',
        'CLAVIER-GAMER-RGB' => 'لوحة مفاتيح ميكانيكية RGB للألعاب',
        'SOURIS-GAMER-7200' => 'فأرة ألعاب 7200 DPI',
        'CASQUE-GAMER' => 'سماعة رأس للألعاب مع ميكروفون',
        'TAPIS-SOURIS-XXL' => 'حصيرة فأرة XXL RGB',
        'SUPPORT-PC-VENTILE' => 'حامل مبرد للحاسوب المحمول',
        'WEBCAM-FHD-1080' => 'كاميرا ويب Full HD 1080p',
        'MICRO-USB-STUDIO' => 'ميكروفون USB ستوديو',
        'T800-ULTRA' => 'ساعة ذكية T800 ألترا',
        'X9-PRO' => 'ساعة ذكية X9 برو',
        'AIR-PRO-TWS' => 'سماعات Air Pro TWS',
        'ECOUTEURS-SPORT-IPX7' => 'سماعات رياضية مقاومة للماء IPX7',
        'ENCEINTE-WATERPROOF' => 'مكبر صوت محمول مقاوم للماء',
        'BARRE-SON-TV' => 'مكبر صوت للتلفاز بلوتوث',
        'CASQUE-ANC' => 'سماعة بلوتوث بخاصية عزل الضوضاء ANC',
        'CHARGEUR-65W-GAN' => 'شاحن سريع 65W GaN',
        'CABLE-USB-C-2M' => 'كابل USB-C مضفر 2 متر',
        'POWERBANK-20000' => 'باور بانك 20000mAh',
        'SUPPORT-VOITURE-MAG' => 'حامل هاتف مغناطيسي للسيارة',
        'COQUE-TRANSPARENTE' => 'غطاء شفاف عالمي للهاتف',
        'SELFIE-STICK-TREPIED' => 'عصا سيلفي مع حامل ثلاثي',
        'VENTILATEUR-USB' => 'مروحة USB صغيرة محمولة',
        'ASPIRATEUR-ROBOT' => 'مكنسة روبوت ذكية',
        'FRITEUSE-AIR-5L' => 'مقلاة هوائية 5 لتر',
        'MIXEUR-PORTABLE' => 'خلاط محمول قابل للشحن',
        'BALANCE-CUISINE' => 'ميزان مطبخ رقمي',
        'LAMPE-SOLAIRE' => 'مصباح شمسي للخارج',
        'HUMIDIFICATEUR-AIR' => 'مرطب هواء بالموجات فوق الصوتية',
        'BROSSE-NETTOYANTE' => 'فرشاة تنظيف كهربائية',
        'ORGANISATEUR-CABLES' => 'منظم كابلات',
        'MINI-FRIGO-USB' => 'ثلاجة صغيرة USB 4 لتر',
        'CAMERA-RECUL' => 'كاميرا خلفية للسيارة',
        'COMPRESSEUR-VOITURE' => 'ضاغط هواء محمول للسيارة',
        'PROJECTEUR-CINEMA' => 'بروجيكتور LED للسينما المنزلية',
        'DRONE-MINI-HD' => 'درون صغير مع كاميرا HD',
        'CONSOLE-RETRO-400' => 'جهاز ألعاب ريترو 400 لعبة',
        'KIT-LED-RGB' => 'طقم إضاءة LED RGB',
        'ASPIRATEUR-TABLE' => 'مكنسة طاولة محمولة',
    ];

    /** @var array<string,array{0:string,1:string}> slug catégorie => [nom ar, nom fr] */
    protected array $categories = [
        'gaming' => ['الألعاب', 'GAMING'],
        'maison' => ['المنزل', 'MAISON'],
        'multimedia' => ['الوسائط المتعددة', 'MULTIMÉDIA'],
        'nos-promos' => ['عروضنا', 'NOS PROMOS'],
    ];

    /** @var array<string,array{0:string,1:string}> url_key CMS => [titre ar, titre fr] */
    protected array $cmsTitles = [
        'about-us' => ['من نحن', 'À propos de nous'],
        'return-policy' => ['سياسة الإرجاع', 'Politique de retour'],
        'refund-policy' => ['سياسة الاسترداد', 'Politique de remboursement'],
        'terms-conditions' => ['الشروط والأحكام', 'Termes et conditions'],
        'terms-of-use' => ['شروط الاستخدام', "Conditions d'utilisation"],
        'customer-service' => ['خدمة العملاء', 'Service client'],
        'whats-new' => ['الجديد', 'Nouveautés'],
        'payment-policy' => ['سياسة الدفع', 'Politique de paiement'],
        'shipping-policy' => ['سياسة الشحن', "Politique d'expédition"],
        'privacy-policy' => ['سياسة الخصوصية', 'Politique de confidentialité'],
    ];

    /** @var array<string,array{0:string,1:string}> titre lien footer EN => [ar, fr] */
    protected array $footerLinkTitles = [
        'About Us' => ['من نحن', 'À propos de nous'],
        'Contact Us' => ['اتصل بنا', 'Contactez-nous'],
        'Customer Service' => ['خدمة العملاء', 'Service client'],
        "What's New" => ['الجديد', 'Nouveautés'],
        'Terms of Use' => ['شروط الاستخدام', "Conditions d'utilisation"],
        'Terms & Conditions' => ['الشروط والأحكام', 'Termes et conditions'],
        'Privacy Policy' => ['سياسة الخصوصية', 'Politique de confidentialité'],
        'Payment Policy' => ['سياسة الدفع', 'Politique de paiement'],
        'Shipping Policy' => ['سياسة الشحن', "Politique d'expédition"],
        'Refund Policy' => ['سياسة الاسترداد', 'Politique de remboursement'],
        'Return Policy' => ['سياسة الإرجاع', 'Politique de retour'],
    ];

    /**
     * Services affichés dans la barre de confiance (services_content).
     * REMPLACEMENT 2026-11-21 : les anciennes affirmations "Free Shipping
     * on all orders", "Emi Available" et "24/7 Support" étaient fausses
     * (frais de livraison flatrate 10 DH, pas de crédit, pas de support
     * 24/7). Les 3 services ci-dessous sont vérifiés sur le site live :
     * paiement à la livraison, délais de livraison annoncés, commande
     * via WhatsApp avec confirmation téléphonique avant expédition.
     * Structure : titre EN => [titre ar, desc ar, titre fr, desc fr, desc EN, icône]
     *
     * @var array<string,array{0:string,1:string,2:string,3:string,4:string,5:string}>
     */
    protected array $services = [
        'Cash on Delivery' => ['الدفع عند الاستلام', 'ادفع نقدا عند استلام طلبك بعد معاينته', 'Paiement à la livraison', 'Payez en espèces à la réception de votre commande, après l\'avoir inspectée', 'Pay cash when you receive your order, after inspecting it', 'icon-dollar-sign'],
        'Fast Delivery' => ['توصيل سريع', '24-48 ساعة في المدن الكبرى، و2-4 أيام في باقي المناطق', 'Livraison rapide', '24-48h dans les grandes villes, 2-4 jours ailleurs', '24-48h in major cities, 2-4 days elsewhere', 'icon-truck'],
        'Order via WhatsApp' => ['الطلب عبر واتساب', 'اطلب عبر واتساب، ويتم تأكيد طلبك هاتفيا قبل الشحن', 'Commande via WhatsApp', 'Commandez via WhatsApp, votre commande est confirmée par téléphone avant l\'expédition', 'Order via WhatsApp; your order is confirmed by phone before shipping', 'icon-support'],
    ];

    public function run(): void
    {
        Model::reguard();

        $this->setupLocalesAndCurrency();
        $this->translateCategories();
        $this->translateProducts();
        $this->translateCmsPages();
        $this->translateThemeCustomizations();
        $this->setupCopyright();

        // Note : `indexer:index` fait `current($this->option('mode'))` —
        // en appel CLI l'option arrive en tableau, mais via Artisan::call
        // elle arrive en string et `current()` plante. On passe donc un
        // tableau, avec un repli CLI en cas d'échec.
        try {
            \Artisan::call('indexer:index', ['--mode' => ['full']]);
        } catch (\Throwable $e) {
            $this->command->warn('indexer:index via Artisan::call a échoué ('.$e->getMessage().') — lancer `php artisan indexer:index --mode=full` en CLI.');
        }
        \Artisan::call('optimize:clear');

        $this->command->info('JemlaArabicTranslationsSeeder: locales ar/fr + MAD + traductions AR/FR appliquées.');
    }

    protected function setupLocalesAndCurrency(): void
    {
        // 1. Locales ar (rtl) + fr (ltr)
        foreach ([
            ['code' => 'ar', 'name' => 'العربية', 'direction' => 'rtl'],
            ['code' => 'fr', 'name' => 'Français', 'direction' => 'ltr'],
        ] as $locale) {
            \DB::table('locales')->updateOrInsert(
                ['code' => $locale['code']],
                array_merge($locale, ['updated_at' => now()])
            );
        }

        $arId = (int) \DB::table('locales')->where('code', 'ar')->value('id');
        $frId = (int) \DB::table('locales')->where('code', 'fr')->value('id');

        // Rattacher ar/fr au canal (en reste attaché) et mettre ar par défaut.
        foreach ([$arId, $frId] as $localeId) {
            \DB::table('channel_locales')->updateOrInsert(
                ['channel_id' => 1, 'locale_id' => $localeId]
            );
        }
        \DB::table('channels')->where('id', 1)->update(['default_locale_id' => $arId]);

        // 2. Devise MAD (symbole DH), devise de base du canal.
        \DB::table('currencies')->updateOrInsert(
            ['code' => 'MAD'],
            [
                'name' => 'Moroccan Dirham',
                'symbol' => 'DH',
                'decimal' => 2,
                'group_separator' => ',',
                'decimal_separator' => '.',
                'currency_position' => 'right',
                'updated_at' => now(),
            ]
        );
        $madId = (int) \DB::table('currencies')->where('code', 'MAD')->value('id');

        // Seule MAD dans le sélecteur (USD n'a pas de taux de change MAD fiable).
        \DB::table('channel_currencies')->where('channel_id', 1)->delete();
        \DB::table('channel_currencies')->insert(['channel_id' => 1, 'currency_id' => $madId]);
        \DB::table('channels')->where('id', 1)->update(['base_currency_id' => $madId]);
    }

    protected function translateCategories(): void
    {
        foreach ($this->categories as $slug => [$nameAr, $nameFr]) {
            $row = \DB::table('category_translations')
                ->where('slug', $slug)->where('locale', 'en')->first();
            if (! $row) {
                continue;
            }

            $category = Category::find($row->category_id);
            if (! $category) {
                continue;
            }

            $trAr = $category->translateOrNew('ar');
            $trAr->name = $nameAr;
            $trAr->slug = $slug;
            $trAr->description = $nameAr;
            $trAr->locale = 'ar';
            $trAr->save();

            $trFr = $category->translateOrNew('fr');
            $trFr->name = $nameFr;
            $trFr->slug = $slug;
            $trFr->description = $nameFr;
            $trFr->locale = 'fr';
            $trFr->save();
        }
    }

    protected function translateProducts(): void
    {
        $productRepo = app(ProductRepository::class);
        $products = \DB::table('products')->where('sku', 'like', 'JEMLA-%')->get();
        $done = 0;

        foreach ($products as $product) {
            $skuSuffix = substr($product->sku, strlen('JEMLA-'));
            $flatEn = \DB::table('product_flat')
                ->where('product_id', $product->id)->where('locale', 'en')->first();
            if (! $flatEn) {
                continue;
            }

            $qty = (int) \DB::table('product_inventories')
                ->where('product_id', $product->id)->value('qty');
            $categoryIds = \DB::table('product_categories')
                ->where('product_id', $product->id)->pluck('category_id')->all();

            $base = [
                'sku' => $product->sku,
                'url_key' => $flatEn->url_key,
                'price' => $flatEn->price,
                'special_price' => $flatEn->special_price,
                'special_price_from' => $flatEn->special_price_from,
                'special_price_to' => $flatEn->special_price_to,
                'weight' => 1,
                'status' => 1,
                'visible_individually' => 1,
                'manage_stock' => 1,
                'guest_checkout' => 1,
                'channel' => 'default',
                'channels' => [1],
                'categories' => $categoryIds,
                'inventories' => [1 => $qty],
            ];

            // FR : les noms saisis en `en` sont déjà en français.
            $productRepo->update(array_merge($base, [
                'locale' => 'fr',
                'name' => $flatEn->name,
                'short_description' => $flatEn->short_description,
                'description' => $flatEn->description,
            ]), $product->id);

            // AR : noms traduits (fallback = nom français si oubli).
            $nameAr = $this->arabicNames[$skuSuffix] ?? $flatEn->name;
            $productRepo->update(array_merge($base, [
                'locale' => 'ar',
                'name' => $nameAr,
                'short_description' => $nameAr.' — التوصيل في جميع أنحاء المغرب، الدفع عند الاستلام.',
                'description' => $nameAr.'. منتج عالي الجودة، يصلك خلال 24-48 ساعة في المدن الكبرى بالمغرب، والدفع عند الاستلام بعد فحص الطرد.',
            ]), $product->id);

            // Régénère les lignes product_flat ar + fr (le refresh boucle
            // sur les locales du canal).
            app(Flat::class)
                ->refresh(Product::find($product->id));

            $done++;
        }

        $this->command->info("Produits traduits AR/FR : {$done}");
    }

    protected function translateCmsPages(): void
    {
        foreach (Page::all() as $page) {
            $en = $page->translate('en');
            if (! $en || ! isset($this->cmsTitles[$en->url_key])) {
                continue;
            }

            [$titleAr, $titleFr] = $this->cmsTitles[$en->url_key];

            $trAr = $page->translateOrNew('ar');
            $trAr->page_title = $titleAr;
            $trAr->url_key = $en->url_key;
            $trAr->html_content = $en->html_content;
            $trAr->meta_title = $titleAr;
            $trAr->locale = 'ar';
            $trAr->save();

            $trFr = $page->translateOrNew('fr');
            $trFr->page_title = $titleFr;
            $trFr->url_key = $en->url_key;
            $trFr->html_content = $en->html_content;
            $trFr->meta_title = $titleFr;
            $trFr->locale = 'fr';
            $trFr->save();
        }
    }

    protected function translateThemeCustomizations(): void
    {
        $rows = \DB::table('theme_customization_translations')->where('locale', 'en')->get();

        foreach ($rows as $row) {
            $options = json_decode($row->options, true);
            if (! is_array($options)) {
                continue;
            }

            // Sections produits (product_carousel / product_grid) : leurs
            // titres sont déjà localisés par JemlaHomepageFixSeeder — ne
            // pas écraser les traductions ar/fr avec les options EN.
            if (isset($options['filters']) && isset($options['title'])) {
                continue;
            }

            // Bandeau d'offre (id 2) : texte traduit — UNIQUEMENT si le
            // HTML est bien le bandeau home-offer. Les autres blocs
            // static_content ont leur propre HTML (sections démo) : on
            // les recopie tels quels, sinon le même bandeau se répète
            // 5 fois sur la page d'accueil (bug corrigé 2026-11-21).
            if (isset($options['html'])) {
                if (str_contains($options['html'], 'home-offer')) {
                    $optionsAr = $options;
                    $optionsFr = $options;
                    // Jemla (round 2C): <p>, not <h1> — the homepage's
                    // only <h1> is the store name (sr-only, in
                    // home/index.blade.php). The CSS selector is
                    // updated by the matching data migration for rows
                    // seeded before this change.
                    $optionsAr['html'] = '<div class="home-offer"><p>احصل على خصم حتى 40% على طلبك الأول — تسوق الآن</p></div>';
                    $optionsFr['html'] = '<div class="home-offer"><p>Profitez de jusqu\'à 40% de réduction sur votre 1ère commande — Achetez maintenant</p></div>';
                    $this->saveThemeTranslation($row->theme_customization_id, 'ar', $optionsAr);
                    $this->saveThemeTranslation($row->theme_customization_id, 'fr', $optionsFr);
                } else {
                    $this->saveThemeTranslation($row->theme_customization_id, 'ar', $options);
                    $this->saveThemeTranslation($row->theme_customization_id, 'fr', $options);
                }

                continue;
            }

            // Liens du footer (id 7) : titres traduits.
            if (isset($options['column_1']) || isset($options['column_2'])) {
                foreach (['ar', 'fr'] as $locale) {
                    $translated = $options;
                    foreach (['column_1', 'column_2'] as $col) {
                        if (! isset($translated[$col])) {
                            continue;
                        }
                        foreach ($translated[$col] as &$link) {
                            $map = $this->footerLinkTitles[$link['title']] ?? null;
                            if ($map) {
                                $link['title'] = $locale === 'ar' ? $map[0] : $map[1];
                            }
                        }
                        unset($link);
                    }
                    $this->saveThemeTranslation($row->theme_customization_id, $locale, $translated);
                }

                continue;
            }

            // Services (id 8) : REMPLACEMENT complet par les 3 services
            // vérifiés (voir $services) — les anciennes valeurs EN/AR/FR
            // contenaient des affirmations fausses (livraison gratuite sur
            // toutes les commandes, EMI, support 24/7).
            if (isset($options['services'])) {
                foreach (['en', 'ar', 'fr'] as $locale) {
                    $built = ['services' => []];

                    foreach ($this->services as $enTitle => $map) {
                        $built['services'][] = [
                            'title' => $locale === 'en' ? $enTitle : ($locale === 'ar' ? $map[0] : $map[2]),
                            'description' => $locale === 'en' ? $map[4] : ($locale === 'ar' ? $map[1] : $map[3]),
                            'service_icon' => $map[5],
                        ];
                    }

                    $this->saveThemeTranslation($row->theme_customization_id, $locale, $built);
                }

                continue;
            }

            // Carrousel d'images / CSS : pas de texte, copie telle quelle.
            $this->saveThemeTranslation($row->theme_customization_id, 'ar', $options);
            $this->saveThemeTranslation($row->theme_customization_id, 'fr', $options);
        }
    }

    protected function saveThemeTranslation(int $customizationId, string $locale, array $options): void
    {
        \DB::table('theme_customization_translations')->updateOrInsert(
            ['theme_customization_id' => $customizationId, 'locale' => $locale],
            ['options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    protected function setupCopyright(): void
    {
        // Le champ copyright est locale_based : il faut une ligne PAR locale,
        // sinon getConfigData() retourne NULL et le shop retombe sur le texte
        // Webkul du fichier de langue (bug vu lors des captures 14).
        foreach ([
            'ar' => '© 2026 Jemla En Ligne. جميع الحقوق محفوظة.',
            'fr' => '© 2026 Jemla En Ligne. Tous droits réservés.',
        ] as $locale => $value) {
            \DB::table('core_config')->updateOrInsert(
                ['code' => 'general.content.footer.copyright_content', 'locale_code' => $locale, 'channel_code' => null],
                ['value' => $value]
            );
        }

        // Bandeau d'offre du header : champs rendus locale_based dans
        // Admin system.php (sinon le texte anglais par défaut s'affiche
        // dans toutes les langues).
        foreach ([
            ['general.content.header_offer.title', 'en', 'Get UPTO 40% OFF on your 1st order'],
            ['general.content.header_offer.title', 'ar', 'احصل على خصم حتى 40% على طلبك الأول'],
            ['general.content.header_offer.title', 'fr', "Profitez de jusqu'à 40% de réduction sur votre 1ère commande"],
            ['general.content.header_offer.redirection_title', 'en', 'SHOP NOW'],
            ['general.content.header_offer.redirection_title', 'ar', 'تسوق الآن'],
            ['general.content.header_offer.redirection_title', 'fr', 'Achetez maintenant'],
        ] as [$code, $locale, $value]) {
            \DB::table('core_config')->updateOrInsert(
                ['code' => $code, 'locale_code' => $locale, 'channel_code' => null],
                ['value' => $value]
            );
        }
    }
}
