<?php

namespace Webkul\Theme\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\Theme\Contracts\ThemeCustomizationTranslation as ThemeCustomizationTranslationContract;

class ThemeCustomizationTranslation extends Model implements ThemeCustomizationTranslationContract
{
    use HasFactory;

    /**
     * Timestamp false of the model
     *
     * @var bool
     */
    public $timestamps = false;

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
     * Daily-deals countdown section (Jemla).
     *
     * @var string
     */
    public const DEALS_COUNTDOWN = 'deals_countdown';

    /**
     * Notification bar section (Jemla).
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
     * Castable.
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
        'name',
        'options',
    ];
}
