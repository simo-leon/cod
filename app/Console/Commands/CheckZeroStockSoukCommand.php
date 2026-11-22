<?php

namespace App\Console\Commands;

use App\Cod\Souk\SoukServiceInterface;
use App\Models\SoukTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Scan every product whose stock is zero and make sure it has a
 * live task on the souk panel (mode "souk", or "auto" with zero
 * stock). A product that already has a live task is skipped, so the
 * command is idempotent — a missed or repeated run never creates
 * duplicates.
 *
 * Scheduled every 30 minutes in routes/console.php; the product and
 * order listeners catch most cases immediately, this command is the
 * safety net for stock changes made any other way (imports, manual
 * edits, refunds).
 */
class CheckZeroStockSoukCommand extends Command
{
    protected $signature = 'souk:check-zero-stock';

    protected $description = 'Assign zero-stock products to the souk panel when their sourcing mode sends them there';

    public function handle(SoukServiceInterface $souk): int
    {
        if (! Schema::hasTable('souk_tasks')) {
            $this->warn('The souk_tasks table is missing — run php artisan migrate to enable the souk panel.');

            return self::FAILURE;
        }

        $result = $souk->checkZeroStock();

        $this->info("Checked {$result['checked']} zero-stock products, created {$result['created']} new souk tasks.");

        return self::SUCCESS;
    }
}
