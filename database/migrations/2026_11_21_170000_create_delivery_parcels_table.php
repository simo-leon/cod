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
        Schema::create('delivery_parcels', function (Blueprint $table) {
            $table->id();
            // Bagisto's orders.id is int unsigned, not bigint: foreignId()
            // would create a bigint column and MySQL rejects the FK (error 3780).
            $table->unsignedInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->string('provider');
            $table->string('parcel_code')->nullable();
            $table->string('status')->default('failed');
            $table->string('last_status')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_parcels');
    }
};
