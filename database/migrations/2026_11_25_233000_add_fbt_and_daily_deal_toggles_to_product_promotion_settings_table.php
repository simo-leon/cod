<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-product offer toggles for the two next store ideas
     * ("Frequently bought together" + daily deals / flash sale),
     * reusing the existing product_promotion_settings table from the
     * cart promotions module — one row per product Simo switched.
     *
     * A product WITHOUT a row (or with the flag true) shows the FBT
     * section on its page and may be suggested as a complementary
     * product. A row with the flag false means Simo excluded the
     * product from the admin product edit page.
     *
     * Daily deals are opt-in: only a product Simo explicitly marked
     * (in_daily_deal = true) AND with a special price below its
     * regular price appears in the daily-deals section. A deal
     * without a price cut is not a deal, so the special price is
     * the deal price — no second price column.
     */
    public function up(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->boolean('show_fbt')->default(true)->after('eligible_buy2_half');
            $table->boolean('fbt_complementary')->default(true)->after('show_fbt');
            $table->boolean('in_daily_deal')->default(false)->after('fbt_complementary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->dropColumn(['show_fbt', 'fbt_complementary', 'in_daily_deal']);
        });
    }
};
