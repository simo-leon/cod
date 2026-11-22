<?php

namespace App\Console\Commands;

use App\Cod\Souk\SoukServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Escalate overdue souk tasks (scheduled, see souk:escalate):
 * assigned tasks nobody claimed before the deadline expire and a
 * fresh assigned task is created so the product stays available;
 * claimed tasks whose buyer never recorded a purchase before the
 * deadline are flagged escalated for the controller. Nothing is
 * ever cancelled automatically — the controller decides.
 *
 * Scheduled hourly in routes/console.php. Run manually with
 * `php artisan souk:escalate`.
 */
class EscalateSoukCommand extends Command
{
    protected $signature = 'souk:escalate';

    protected $description = 'Expire unclaimed overdue souk tasks (recreating them) and flag stalled claimed tasks as escalated';

    public function handle(SoukServiceInterface $souk): int
    {
        if (! Schema::hasTable('souk_tasks')) {
            $this->warn('The souk_tasks table is missing — run php artisan migrate to enable the souk panel.');

            return self::FAILURE;
        }

        $result = $souk->escalateOverdue();

        $this->info("Expired: {$result['expired']}, recreated: {$result['recreated']}, escalated stalled claims: {$result['escalated']}.");

        return self::SUCCESS;
    }
}
