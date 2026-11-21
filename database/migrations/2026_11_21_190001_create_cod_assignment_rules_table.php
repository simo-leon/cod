<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cod_assignment_rules', function (Blueprint $table) {
            $table->id();
            // Rule kind: 'percentage' (share of new orders) or 'product'
            // (all orders containing one SKU go to one confirmer).
            $table->string('type');
            // The confirmer this rule applies to (admins.id is int unsigned).
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            // Share of orders for percentage rules (relative weight: 50
            // vs 30 vs 20 behaves like 50% / 30% / 20%). Unused for
            // product rules.
            $table->unsignedInteger('weight')->default(0);
            // Smooth weighted round-robin state: the distributor adds the
            // weight on every round and subtracts the total from the
            // winner, so the split stays fair over time instead of
            // drifting.
            $table->integer('current_weight')->default(0);
            // Product SKU for product rules, null for percentage rules.
            $table->string('product_sku')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One product rule per confirmer per SKU. MySQL treats NULLs
            // as distinct, so percentage rules (product_sku NULL) are
            // not blocked by this index; they are enforced as one per
            // confirmer in the service instead.
            $table->unique(['admin_id', 'product_sku']);
            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_assignment_rules');
    }
};
