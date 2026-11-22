<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every dirham that moves for a souk task is recorded here, so
     * the question "who paid the purchase price in advance?" always
     * has an auditable answer:
     * - advance: money given to the buyer before / at purchase time
     *   (the "advance payment" model).
     * - reimbursement: money paid back to the buyer after the goods
     *   were received and verified (the "reimbursement on delivery"
     *   model).
     *
     * paid_by is the admin who handed over the money (controller /
     * supervisor / owner); buyer_id is the souk buyer who received
     * it. A task becomes "paid" only when the recorded payments cover
     * its purchase price — no payment is ever created automatically.
     */
    public function up(): void
    {
        Schema::create('souk_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('souk_task_id');
            $table->foreign('souk_task_id')->references('id')->on('souk_tasks')->cascadeOnDelete();
            $table->unsignedInteger('buyer_id');
            $table->foreign('buyer_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->unsignedInteger('paid_by');
            $table->foreign('paid_by')->references('id')->on('admins')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('type');
            $table->text('note')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index('souk_task_id');
            $table->index(['buyer_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('souk_payments');
    }
};
