<?php

namespace App\Console\Commands;

use App\Cod\Orders\OrderServiceInterface;
use Illuminate\Console\Command;

/**
 * Scheduled import of cash-on-delivery store orders into the COD System.
 *
 * Pulls every COD store order that has no COD record yet. Idempotent:
 * running it twice imports nothing the second time. Run manually with
 * `php artisan cod:import-orders`, or let the hourly schedule in
 * routes/console.php run it.
 */
class ImportCodOrdersCommand extends Command
{
    protected $signature = 'cod:import-orders {--limit=500 : Maximum number of orders to import}';

    protected $description = 'Import new cash-on-delivery store orders into the COD System';

    public function handle(OrderServiceInterface $orders): int
    {
        $result = $orders->importPendingOrders((int) $this->option('limit'));

        $this->info("Imported: {$result['imported']}");
        $this->info("Already imported: {$result['already_imported']}");

        if ($result['failed'] > 0) {
            $this->warn("Failed: {$result['failed']}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
