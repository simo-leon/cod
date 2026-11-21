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
        Schema::create('cod_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cod_order_id')->nullable()->constrained('cod_orders')->nullOnDelete();
            // out = we sent it, in = the customer sent it.
            $table->string('direction');
            // Provider used: meta, evolution or log.
            $table->string('provider');
            // Customer phone, normalized to bare international digits.
            $table->string('phone')->nullable();
            // Message text (outbound template rendered, inbound reply).
            $table->text('body')->nullable();
            // Provider message id (Meta wamid, Evolution key id).
            $table->string('message_id')->nullable();
            // sent, failed, received, logged (log provider only).
            $table->string('status');
            // Provider error text on failed sends — never a token.
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('cod_order_id');
            $table->index('phone');
            // Inbound de-duplication: a redelivered webhook carries the
            // same provider + message id. MySQL unique indexes allow
            // multiple NULLs, so outbound rows without an id are fine.
            $table->unique(['provider', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_whatsapp_messages');
    }
};
