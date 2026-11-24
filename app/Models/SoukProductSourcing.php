<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

/**
 * How one product is sourced: own stock, souk, or automatic.
 *
 * Mode "auto" (the default) means own stock while it lasts; the
 * moment the stock hits zero the product is assigned to the souk
 * panel automatically. A row is created lazily the first time a
 * product is sourced or its mode is changed — a product without a
 * row behaves exactly like mode "auto" with no cap and no minimum
 * margin.
 */
class SoukProductSourcing extends Model
{
    public const MODE_OWN_STOCK = 'own_stock';

    public const MODE_SOUK = 'souk';

    public const MODE_AUTO = 'auto';

    public const MODES = [
        self::MODE_OWN_STOCK,
        self::MODE_SOUK,
        self::MODE_AUTO,
    ];

    protected $table = 'souk_product_sourcing';

    protected $fillable = [
        'product_id',
        'mode',
        'price_cap',
        'min_margin_percent',
    ];

    protected $casts = [
        'price_cap' => 'decimal:2',
        'min_margin_percent' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
