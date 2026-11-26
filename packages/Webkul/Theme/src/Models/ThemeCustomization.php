<?php

namespace Webkul\Theme\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Webkul\Admin\Database\Factories\ThemeFactory;
use Webkul\Core\Eloquent\TranslatableModel;
use Webkul\Theme\Contracts\ThemeCustomization as ThemeCustomizationContract;

class ThemeCustomization extends TranslatableModel implements ThemeCustomizationContract
{
    use HasFactory;

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    public $translatedAttributes = [
        'options',
    ];

    /**
     * With the translations given attributes
     *
     * @var array
     */
    protected $with = ['translations'];

    /**
     * Image carousel precision.
     *
     * @var string
     */
    public const IMAGE_CAROUSEL = 'image_carousel';

    /**
     * Product carousel precision.
     *
     * @var string
     */
    public const PRODUCT_CAROUSEL = 'product_carousel';

    /**
     * Product grid precision.
     *
     * @var string
     */
    public const PRODUCT_GRID = 'product_grid';

    /**
     * Category carousel precision.
     *
     * @var string
     */
    public const CATEGORY_CAROUSEL = 'category_carousel';

    /**
     * Footer links precision.
     *
     * @var string
     */
    public const FOOTER_LINKS = 'footer_links';

    /**
     * Static precision.
     *
     * @var string
     */
    public const STATIC_CONTENT = 'static_content';

    /**
     * Services Content.
     *
     * @var string
     */
    public const SERVICES_CONTENT = 'services_content';

    /**
     * Daily-deals countdown section (Jemla: a topic in the type
     * dropdown, so Simo can place it anywhere on the homepage and
     * give it its own duration/limit instead of only the global
     * Homepage settings).
     *
     * @var string
     */
    public const DEALS_COUNTDOWN = 'deals_countdown';

    /**
     * Notification bar section (Jemla: a topic in the type
     * dropdown, with its own per-locale message/link — the
     * site-wide header bar stays in Configuration → General →
     * Homepage / header offer).
     *
     * @var string
     */
    public const NOTIFICATION_BAR = 'notification_bar';

    /**
     * Best sellers section (Jemla: products ranked by ordered
     * quantity — Amazon "Best Sellers" / AliExpress "Top Rankings").
     *
     * @var string
     */
    public const BEST_SELLERS = 'best_sellers';

    /**
     * Shop by category section (Jemla: image tiles per top-level
     * category — Amazon "Shop by Category" / Shopify featured
     * collections).
     *
     * @var string
     */
    public const SHOP_BY_CATEGORY = 'shop_by_category';

    /**
     * Customer reviews section (Jemla: latest approved product
     * reviews — Shopify social proof).
     *
     * @var string
     */
    public const CUSTOMER_REVIEWS = 'customer_reviews';

    /**
     * Trust badges section (Jemla: COD promises — Shopify trust
     * bar / COD landing-page trust signals).
     *
     * @var string
     */
    public const TRUST_BADGES = 'trust_badges';

    /**
     * Cast options field to array.
     *
     * @var array
     */
    protected $casts = [
        'options' => 'array',
    ];

    /**
     * Add fillable properties
     *
     * @var array
     */
    protected $fillable = [
        'type',
        'name',
        'options',
        'sort_order',
        'status',
        'channel_id',
        'theme_code',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return ThemeFactory::new();
    }
}
