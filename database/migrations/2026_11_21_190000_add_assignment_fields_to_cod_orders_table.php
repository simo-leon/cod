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
            // Confirmer the order is assigned to (admins.id is int
            // unsigned, not bigint: foreignId() would fail on MySQL).
            $table->unsignedInteger('assigned_to')->nullable()->after('status');
            $table->foreign('assigned_to')->references('id')->on('admins')->nullOnDelete();
            // When the order was assigned (used for the daily limit).
            $table->dateTime('assigned_at')->nullable()->after('assigned_to');
            // How many confirmation calls were logged for this order.
            $table->unsignedInteger('confirmation_attempts')->default(0)->after('assigned_at');
            // Callback date promised on a postponed call, if any.
            $table->dateTime('callback_at')->nullable()->after('confirmation_attempts');

            $table->index('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['assigned_to']);
            $table->dropColumn(['assigned_to', 'assigned_at', 'confirmation_attempts', 'callback_at']);
        });
    }
};
