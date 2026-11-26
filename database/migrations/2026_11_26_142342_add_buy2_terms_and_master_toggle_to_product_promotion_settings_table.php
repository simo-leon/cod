<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-product buy-2 terms + one master toggle for the product's
     * offers, on the existing product_promotion_settings table.
     *
     * - offers_enabled (default true): one switch for all of the
     *   product's offers. Off means the product takes part in no
     *   offer at all (free gift, low-stock badge, buy-2, FBT,
     *   daily deals) — the individual toggles keep their values, so
     *   switching it back on restores them as they were.
     * - buy2_min_qty (null = the global buy count): how many units
     *   the customer must buy to earn the discounted unit
     *   ("اشتري N"), e.g. 4 for an expensive product instead of the
     *   global 2. The group size is min_qty + 1.
     * - buy2_discount_percent (null = the global percent): the
     *   discount percent of the extra unit, e.g. 30 instead of the
     *   global 50. Null in both columns means "use the global
     *   settings" — no behaviour change for products Simo never
     *   touches.
     */
    public function up(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->boolean('offers_enabled')->default(true)->after('include_in_abandoned_cart_reminder');
            $table->unsignedTinyInteger('buy2_min_qty')->nullable()->after('offers_enabled');
            $table->unsignedTinyInteger('buy2_discount_percent')->nullable()->after('buy2_min_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->dropColumn(['offers_enabled', 'buy2_min_qty', 'buy2_discount_percent']);
        });
    }
};
