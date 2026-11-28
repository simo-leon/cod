<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jemla: composite index for the EAV lookups in the storefront
     * listing query (ProductRepository::searchFromDatabase).
     *
     * The listing query probes product_attribute_values by
     * (product_id, attribute_id) once per product — for the status,
     * visible_individually and url_key checks (now WHERE EXISTS) and
     * for the name/sort/filter joins. The existing indexes only
     * cover product_id alone (prod_attr_product_id_idx, ~20 rows per
     * product) or start with (channel, locale, ...) and cannot be
     * used for a product_id lookup. With this index each probe reads
     * exactly the rows for that attribute (1 for status, 3 for
     * url_key across locales). Measured 2026-11-28 at 8,050
     * products: the three EAV joins were ~1.3s of a 1.45s main
     * query; the index is the other half of that fix.
     */
    public function up(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->index(['product_id', 'attribute_id'], 'pav_product_id_attribute_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex('pav_product_id_attribute_id_idx');
        });
    }
};
