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
        Schema::create('cod_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cod_order_id')->constrained('cod_orders')->cascadeOnDelete();
            // The confirmer who made the call (admins.id is int unsigned).
            $table->unsignedInteger('confirmer_id')->nullable();
            $table->foreign('confirmer_id')->references('id')->on('admins')->nullOnDelete();
            // Call outcome: confirmed, no_answer, refused, postponed.
            $table->string('result');
            $table->text('note')->nullable();
            // Callback date promised on a postponed call, if any.
            $table->dateTime('callback_at')->nullable();
            $table->dateTime('called_at');
            $table->timestamps();

            $table->index('cod_order_id');
            $table->index('confirmer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_call_logs');
    }
};
