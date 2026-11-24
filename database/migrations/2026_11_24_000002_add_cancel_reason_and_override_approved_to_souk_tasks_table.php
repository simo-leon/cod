<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * T12: the reason a souk task was cancelled gets its own column
     * instead of overwriting the purchase note.
     *
     * M25: whether the warehouse controller explicitly approved a
     * purchase that was over the price cap or below the minimum
     * margin at verification time.
     */
    public function up(): void
    {
        Schema::table('souk_tasks', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('note');
            $table->boolean('override_approved')->default(false)->after('below_margin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('souk_tasks', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'override_approved']);
        });
    }
};
