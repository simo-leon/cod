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
        Schema::create('cod_orders', function (Blueprint $table) {
            $table->id();
            // Bagisto's orders.id is int unsigned, not bigint: foreignId()
            // would create a bigint column and MySQL rejects the FK (error 3780).
            $table->unsignedInteger('order_id')->nullable();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            // Original store order identifier (order increment_id). Unique so
            // the webhook, the import endpoint and the scheduled import can
            // never create a second COD record for the same store order.
            $table->string('external_id')->unique();
            // COD workflow status: pending_confirmation, confirmed, canceled,
            // sent_to_delivery, delivered, returned.
            $table->string('status')->default('pending_confirmation');
            // Status of the original store order at the last sync.
            $table->string('store_status')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->decimal('grand_total', 12, 4)->default(0);
            $table->string('currency_code')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            // Where the record came from: webhook, api, import.
            $table->string('source')->default('webhook');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_orders');
    }
};
