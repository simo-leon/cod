<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-product offer toggles for the two new store ideas (low-stock
     * badge + buy-2-get-3rd-half-price), reusing the existing
     * product_promotion_settings table from the cart promotions
     * module — one row per product that Simo switched off.
     *
     * A product WITHOUT a row (or with the flag true) participates:
     * the low-stock badge shows when its stock is at/below the
     * threshold, and it counts towards the buy-2 groups. A row with
     * the flag false means Simo excluded the product from the admin
     * product edit page.
     */
    public function up(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->boolean('show_low_stock_badge')->default(true)->after('eligible_as_gift');
            $table->boolean('eligible_buy2_half')->default(true)->after('show_low_stock_badge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->dropColumn(['show_low_stock_badge', 'eligible_buy2_half']);
        });
    }
};
