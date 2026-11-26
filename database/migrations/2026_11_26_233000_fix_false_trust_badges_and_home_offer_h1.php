<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jemla (round 2C, issue #4 + #8): fix false trust claims and the promo
 * slogan <h1> in the already-seeded theme customizations.
 *
 * The code fix lives in the seeders (Installer
 * ThemeCustomizationTableSeeder + JemlaArabicTranslationsSeeder), but
 * seeders do not run on deploy — so this migration rewrites the rows
 * that are already in the database, local and live. It is idempotent
 * (re-running changes nothing) and only touches rows that still match
 * the exact false patterns:
 *
 * 1. services_content rows whose service titles are the old Velocity
 *    defaults (en/ar/fr: Free Shipping / Emi Available / Product
 *    Replace / 24/7 Support) — replaced by the three verified Jemla
 *    facts: cash on delivery, the announced delivery times, ordering
 *    via WhatsApp with phone confirmation before shipping.
 * 2. home-offer banner rows whose HTML still wraps the promo slogan
 *    in an <h1> — demoted to <p> (the homepage's only <h1> is now the
 *    sr-only store name in home/index.blade.php); the stored CSS
 *    selector `.home-offer h1` is retargeted to `.home-offer p` so the
 *    banner looks exactly the same.
 * 3. The live daily-deals static block's trust bar, which claims bare
 *    "Livraison gratuite" — free delivery only starts at 300 DH
 *    (general.promotions.settings.free_delivery_threshold, default
 *    300), so the badge becomes "Livraison gratuite dès 300 DH".
 */
return new class extends Migration
{
    /**
     * The three verified Jemla services, per locale (other locales
     * fall back to English — same as the seeder).
     *
     * @var array<string, array<int, array{title: string, description: string, service_icon: string}>>
     */
    protected array $jemlaServices = [
        'ar' => [
            ['title' => 'الدفع عند الاستلام', 'description' => 'ادفع نقدا عند استلام طلبك بعد معاينته', 'service_icon' => 'icon-dollar-sign'],
            ['title' => 'توصيل سريع', 'description' => '24-48 ساعة في المدن الكبرى، و2-4 أيام في باقي المناطق', 'service_icon' => 'icon-truck'],
            ['title' => 'الطلب عبر واتساب', 'description' => 'اطلب عبر واتساب، ويتم تأكيد طلبك هاتفيا قبل الشحن', 'service_icon' => 'icon-support'],
        ],
        'fr' => [
            ['title' => 'Paiement à la livraison', 'description' => 'Payez en espèces à la réception de votre commande, après l\'avoir inspectée', 'service_icon' => 'icon-dollar-sign'],
            ['title' => 'Livraison rapide', 'description' => '24-48h dans les grandes villes, 2-4 jours ailleurs', 'service_icon' => 'icon-truck'],
            ['title' => 'Commande via WhatsApp', 'description' => 'Commandez via WhatsApp, votre commande est confirmée par téléphone avant l\'expédition', 'service_icon' => 'icon-support'],
        ],
        'en' => [
            ['title' => 'Cash on Delivery', 'description' => 'Pay cash when you receive your order, after inspecting it', 'service_icon' => 'icon-dollar-sign'],
            ['title' => 'Fast Delivery', 'description' => '24-48h in major cities, 2-4 days elsewhere', 'service_icon' => 'icon-truck'],
            ['title' => 'Order via WhatsApp', 'description' => 'Order via WhatsApp; your order is confirmed by phone before shipping', 'service_icon' => 'icon-support'],
        ],
    ];

    /** Titles of the old false Velocity defaults, in every seeded locale. */
    protected array $falseServiceTitles = [
        'Free Shipping', 'Emi Available', 'Product Replace', '24/7 Support',
        'الشحن المجاني', 'توفر EMI', 'استبدال المنتج', 'الدعم على مدار الساعة',
        'Livraison gratuite', 'EMI disponible', 'Remplacement de produit', 'Support 24/7',
    ];

    public function up(): void
    {
        $rows = DB::table('theme_customization_translations')->get(['id', 'locale', 'options']);

        foreach ($rows as $row) {
            $options = json_decode((string) $row->options, true);

            if (! is_array($options)) {
                continue;
            }

            $changed = false;

            // 1. False services_content claims -> verified Jemla services.
            if (isset($options['services']) && is_array($options['services'])) {
                foreach ($options['services'] as $service) {
                    if (in_array(trim((string) ($service['title'] ?? '')), $this->falseServiceTitles, true)) {
                        $options['services'] = $this->jemlaServices[$row->locale]
                            ?? $this->jemlaServices['en'];

                        $changed = true;

                        break;
                    }
                }
            }

            // 2. home-offer promo slogan: <h1> -> <p> (HTML + stored CSS).
            if (isset($options['html']) && str_contains($options['html'], 'home-offer')) {
                $html = str_replace(['<h1>', '</h1>'], ['<p>', '</p>'], $options['html']);

                if ($html !== $options['html']) {
                    $options['html'] = $html;
                    $changed = true;
                }
            }

            if (isset($options['css']) && str_contains($options['css'], '.home-offer h1')) {
                $options['css'] = str_replace('.home-offer h1', '.home-offer p', $options['css']);
                $changed = true;
            }

            // 3. Live deals trust bar: bare "Livraison gratuite" -> with
            // the verified 300 DH threshold (only inside trust-bar blocks).
            if (
                isset($options['html'])
                && str_contains($options['html'], 'trust-bar')
                && str_contains($options['html'], '<span>Livraison gratuite</span>')
            ) {
                $options['html'] = str_replace(
                    '<span>Livraison gratuite</span>',
                    '<span>Livraison gratuite dès 300 DH</span>',
                    $options['html']
                );

                $changed = true;
            }

            if ($changed) {
                DB::table('theme_customization_translations')
                    ->where('id', $row->id)
                    ->update([
                        'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Content migration: the old values were false claims, there is
        // nothing truthful to restore. No-op by design.
    }
};
