<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One sourcing row per product: how the product is supplied.
     * - own_stock: the store's own physical stock only, never goes to
     *   the souk panel.
     * - souk: always sourced from the souk panel, even when stock
     *   exists (the store does not keep this product).
     * - auto (default): own stock while it lasts; the moment the
     *   stock hits zero the product is assigned to the souk panel
     *   automatically.
     *
     * price_cap is the most the buyer may pay for one unit in the
     * souk (DH); null means "use the default cap from the admin".
     * min_margin_percent is the smallest acceptable margin (sale
     * price minus purchase price, as a percentage of the sale price);
     * null means "use the admin default".
     */
    public function up(): void
    {
        Schema::create('souk_product_sourcing', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->string('mode')->default('auto');
            $table->decimal('price_cap', 12, 2)->nullable();
            $table->decimal('min_margin_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique('product_id');
            $table->index('mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('souk_product_sourcing');
    }
};
