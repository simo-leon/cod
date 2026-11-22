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
            // How many WhatsApp reminders were sent for this order.
            // cod:remind-unconfirmed sends up to the configured number
            // of reminders (admin, default 1), spaced by the reminder
            // delay; it is incremented only on a successful send, so a
            // failed attempt is retried on the next run.
            $table->unsignedInteger('reminder_count')->default(0)->after('reminder_sent_at');
            // When the order was moved to `whatsapp_unconfirmed` by
            // cod:remind-unconfirmed (no reply before the conversion
            // deadline). The order is never canceled automatically.
            $table->dateTime('whatsapp_unconfirmed_at')->nullable()->after('reminder_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropColumn(['reminder_count', 'whatsapp_unconfirmed_at']);
        });
    }
};
