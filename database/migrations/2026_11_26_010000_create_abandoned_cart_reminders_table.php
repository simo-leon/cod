<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart WhatsApp reminders (idea 7): one row per cart that
 * was reminded, so a re-run (or a second scheduled run) can never
 * send the same cart twice. A row is written only when the message
 * was actually sent; a failed send leaves no row, so the next run
 * retries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abandoned_cart_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id')->unique();
            $table->string('phone', 32);
            $table->string('locale', 10)->default('fr');
            $table->string('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_cart_reminders');
    }
};
