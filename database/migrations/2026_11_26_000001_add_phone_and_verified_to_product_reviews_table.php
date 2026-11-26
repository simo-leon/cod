<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product reviews (idea 5): the reviewer's phone number (guests
 * type it in the review form; logged-in customers use their account
 * phone) and whether the review is a verified purchase — the phone
 * or email matched an order containing this product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('customer_id');
            $table->boolean('is_verified_purchase')->default(false)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_verified_purchase']);
        });
    }
};
