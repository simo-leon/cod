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
        Schema::table('delivery_parcels', function (Blueprint $table) {
            // Raw tracking events from the provider's last successful
            // track call (kept separate from `response`, which holds
            // the parcel-creation response).
            $table->json('tracking_events')->nullable()->after('response');
        });

        Schema::table('cod_orders', function (Blueprint $table) {
            // Phase 6: one customer WhatsApp notification per delivery
            // event per order. Each column is set only when that
            // notification was sent successfully, so a failed send
            // leaves it null (retried on the next sync) and a sent one
            // is never repeated, even if the sync runs again.
            $table->dateTime('shipped_notification_sent_at')->nullable()->after('last_reply_at');
            $table->dateTime('out_for_delivery_notification_sent_at')->nullable()->after('shipped_notification_sent_at');
            $table->dateTime('delivered_notification_sent_at')->nullable()->after('out_for_delivery_notification_sent_at');
            $table->dateTime('returned_notification_sent_at')->nullable()->after('delivered_notification_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_parcels', function (Blueprint $table) {
            $table->dropColumn('tracking_events');
        });

        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipped_notification_sent_at',
                'out_for_delivery_notification_sent_at',
                'delivered_notification_sent_at',
                'returned_notification_sent_at',
            ]);
        });
    }
};
