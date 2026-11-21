<?php

namespace App\Console\Commands;

use App\Cod\Confirmation\AssignmentServiceInterface;
use Illuminate\Console\Command;

/**
 * Distribute unassigned pending COD orders to confirmers (phase 3).
 *
 * Uses the supervisor's rules: product rules first, then the
 * percentage distribution, respecting the daily limit per confirmer.
 * Idempotent: running it twice assigns nothing the second time.
 * Run manually with `php artisan cod:assign-orders`, or from the
 * "Distribute" button on the COD orders list and the assignment page.
 */
class AssignCodOrdersCommand extends Command
{
    protected $signature = 'cod:assign-orders {--limit=200 : Maximum number of orders to distribute}';

    protected $description = 'Distribute unassigned pending COD orders to confirmers';

    public function handle(AssignmentServiceInterface $assignment): int
    {
        $result = $assignment->assignUnassigned((int) $this->option('limit'));

        $this->info("Assigned: {$result['assigned']}");

        if ($result['skipped'] > 0) {
            $this->warn("Skipped (no confirmer available): {$result['skipped']}");
        }

        return self::SUCCESS;
    }
}
