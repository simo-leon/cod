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
        Schema::table('cod_orders', function (Blueprint $table) {
            // When the WhatsApp confirmation message was sent (phase 4).
            // Set only on success: a failed send leaves it null so the
            // next trigger retries, and a set value makes
            // sendConfirmation() refuse to send twice.
            $table->dateTime('confirmation_sent_at')->nullable()->after('callback_at');
            // When the reminder was sent (phase 4). One reminder only:
            // cod:remind-unconfirmed skips orders where this is set.
            $table->dateTime('reminder_sent_at')->nullable()->after('confirmation_sent_at');
            // Last WhatsApp provider used for this order ('meta',
            // 'evolution' or 'log'), for the audit trail.
            $table->string('whatsapp_provider')->nullable()->after('reminder_sent_at');
            // When the customer last replied on WhatsApp (any reply,
            // even one that is not a confirmation answer). The
            // reminder command skips orders with a reply.
            $table->dateTime('last_reply_at')->nullable()->after('whatsapp_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropColumn(['confirmation_sent_at', 'reminder_sent_at', 'whatsapp_provider', 'last_reply_at']);
        });
    }
};
