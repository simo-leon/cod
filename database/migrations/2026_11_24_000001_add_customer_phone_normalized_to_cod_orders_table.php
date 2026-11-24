<?php

use App\Cod\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * M17: WhatsApp replies used to load every pending order into PHP
     * and compare phones there. The normalized phone (bare
     * international digits, see App\Cod\Support\PhoneNumber) lets the
     * match run in SQL on an indexed column. Existing rows are
     * backfilled here, so old orders match too.
     */
    public function up(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->string('customer_phone_normalized')->nullable()->after('customer_phone');
            $table->index('customer_phone_normalized');
        });

        DB::table('cod_orders')
            ->whereNotNull('customer_phone')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('cod_orders')
                        ->where('id', $order->id)
                        ->update(['customer_phone_normalized' => PhoneNumber::normalize((string) $order->customer_phone)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropIndex(['customer_phone_normalized']);
            $table->dropColumn('customer_phone_normalized');
        });
    }
};
