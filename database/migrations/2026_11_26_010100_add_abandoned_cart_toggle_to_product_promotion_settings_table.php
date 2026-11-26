<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart WhatsApp reminders (idea 7): per-product toggle in
 * the same table as the other offer toggles. A product without a row
 * is included in the reminders; a false flag excludes it — a cart
 * with only excluded products gets no reminder at all, and a cart
 * with mixed products lists only the included ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->boolean('include_in_abandoned_cart_reminder')->default(true)->after('show_in_tracking');
        });
    }

    public function down(): void
    {
        Schema::table('product_promotion_settings', function (Blueprint $table) {
            $table->dropColumn('include_in_abandoned_cart_reminder');
        });
    }
};
