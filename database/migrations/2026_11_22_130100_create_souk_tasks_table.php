<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row is one souk sourcing job: a product (and quantity) that
     * has to be bought in the souk, brought to the warehouse and
     * checked by the warehouse controller.
     *
     * Statuses:
     * - assigned: on the panel, waiting for a buyer to claim it.
     * - claimed: one buyer locked it (first claim wins).
     * - purchased: the buyer bought it in the souk and recorded the
     *   price they paid.
     * - received: the goods entered the warehouse (stock increased).
     * - verified: the controller accepted the goods.
     * - rejected: the controller refused the goods (with a reason);
     *   a fresh assigned task is created for the same product so
     *   another buyer can take it, and the rejection stays on the
     *   first buyer's record.
     * - paid: the buyer was paid (recorded in souk_payments).
     * - canceled: cancelled by the controller / supervisor.
     * - expired: nobody claimed it before the deadline; a fresh
     *   assigned task is created so the product stays available.
     *
     * cod_order_id links the task to the COD order that needs the
     * product when the task was created from an order; it is null
     * when the task came from a plain zero-stock scan.
     */
    public function up(): void
    {
        Schema::create('souk_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unsignedBigInteger('cod_order_id')->nullable();
            $table->foreign('cod_order_id')->references('id')->on('cod_orders')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->string('status')->default('assigned');
            $table->unsignedInteger('buyer_id')->nullable();
            $table->foreign('buyer_id')->references('id')->on('admins')->nullOnDelete();
            $table->unsignedInteger('controller_id')->nullable();
            $table->foreign('controller_id')->references('id')->on('admins')->nullOnDelete();
            // Sale price of one unit at task creation (snapshot from
            // product_flat, null when unknown) and the price the buyer
            // actually paid in the souk for one unit.
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            // True when the recorded purchase price is above the
            // product cap (or the admin default cap), or the margin is
            // below the minimum: the controller sees a clear flag on
            // the board and on the verification form.
            $table->boolean('over_cap')->default(false);
            $table->boolean('below_margin')->default(false);
            $table->boolean('is_escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['status', 'deadline_at']);
            $table->index('buyer_id');
            $table->index('cod_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('souk_tasks');
    }
};
