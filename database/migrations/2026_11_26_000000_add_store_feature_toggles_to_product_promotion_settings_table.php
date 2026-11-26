<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store features (ideas 5-6): per-product toggles for product
 * reviews and order tracking, in the same table as the other
 * offer toggles. A product without a row participates in both
 * (reviews allowed, shown in tracking); a false flag opts it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->boolean('allow_reviews')->default(true)->after('in_daily_deal');
            $table->boolean('show_in_tracking')->default(true)->after('allow_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_reviews', 'show_in_tracking']);
        });
    }
};
