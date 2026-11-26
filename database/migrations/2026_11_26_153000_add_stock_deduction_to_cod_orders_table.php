<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Jemla sells over stock: the physical stock is deducted only
     * when the COD order is confirmed (see
     * App\Cod\Inventory\CodStockService), and restored when the
     * order is refused or cancelled.
     *
     * - stock_deducted: the confirmation deduction already ran for
     *   this COD order. It is the idempotency guard (a confirmation
     *   must never deduct twice) and the flag the shipment and
     *   invoice repositories check before decrementing the stock a
     *   second time.
     * - stock_deductions: the quantities actually deducted, as
     *   [{product_id, qty}] — the exact amounts the restore puts
     *   back, so a product without an inventory row (deducted 0)
     *   never gains stock on cancel.
     */
    public function up(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('cod_orders', 'stock_deducted')) {
                $table->boolean('stock_deducted')->default(false)->after('status');
            }

            if (! Schema::hasColumn('cod_orders', 'stock_deductions')) {
                $table->json('stock_deductions')->nullable()->after('stock_deducted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cod_orders', function (Blueprint $table) {
            $table->dropColumn(['stock_deducted', 'stock_deductions']);
        });
    }
};
