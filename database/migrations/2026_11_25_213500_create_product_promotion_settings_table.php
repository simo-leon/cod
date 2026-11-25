<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per product that Simo excluded from the free-gift offers.
     * A product WITHOUT a row is eligible as a free gift whenever its
     * price is within the gift tier cap. A row with eligible_as_gift
     * = false means Simo switched the product off from the admin
     * product edit page, so it never appears in the gift picker.
     * Rows with eligible_as_gift = true are kept so the choice is
     * explicit and survives re-edits.
     */
    public function up(): void
    {
        Schema::create('product_promotion_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->boolean('eligible_as_gift')->default(true);
            $table->timestamps();

            $table->unique('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_promotion_settings');
    }
};
