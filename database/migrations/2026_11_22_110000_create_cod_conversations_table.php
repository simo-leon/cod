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
        Schema::create('cod_conversations', function (Blueprint $table) {
            $table->id();
            // Customer phone, normalized to bare international digits
            // (see App\Cod\Support\PhoneNumber), so the same customer
            // is one conversation whatever format they wrote in.
            $table->string('phone')->index();
            // The COD order this conversation is about, when one
            // exists: either the pending order the customer replied
            // about, or the order created from a product inquiry.
            $table->foreignId('cod_order_id')->nullable()->constrained('cod_orders')->nullOnDelete();
            // SKU of the product the customer inquired about, when the
            // conversation started from a product question.
            $table->string('product_sku')->nullable();
            // collecting_info, awaiting_confirmation, confirmed,
            // canceled, escalated (handed to a human).
            $table->string('state')->default('collecting_info');
            // Collected information and flow bookkeeping: product
            // (sku, name, price), answers (name, city, address,
            // phone), unclear_attempts. JSON, never a separate table:
            // the shape is the flow's business, not a relation.
            $table->json('collected')->nullable();
            // False once a human takes over (handoff button in the
            // inbox, or an AI escalation): the agent stops replying.
            $table->boolean('ai_enabled')->default(true);
            // When the conversation was handed to a human.
            $table->dateTime('escalated_at')->nullable();
            // When the last message (in or out) was recorded, for the
            // inbox ordering.
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->index('cod_order_id');
            $table->index('state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_conversations');
    }
};
