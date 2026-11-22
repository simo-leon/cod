<?php

namespace App\Console\Commands;

use App\Cod\Delivery\TrackingSyncServiceInterface;
use Illuminate\Console\Command;

/**
 * Sync delivery tracking for every sent parcel (COD System phase 6).
 *
 * For each parcel in `delivery_parcels` with status 'sent' and a
 * tracking code, the provider's trackParcel() is called, the parcel
 * `last_status` is updated (unified status, see
 * TrackingStatusMapper), the linked COD order status is synced, and
 * the customer is notified once per delivery event on WhatsApp.
 *
 * A provider failure (timeout, API error, unrecognized response) is
 * stored in the parcel's `error_message` and counted — it never
 * stops the run and never changes the parcel or COD status. A
 * notification failure likewise never breaks the sync. A parcel in
 * a final state (delivered / returned / cancelled) gets no provider
 * call; only its pending customer notification is still retried.
 *
 * Idempotent: a re-run with no status change writes nothing, moves
 * no COD order and sends no notification twice.
 *
 * Scheduled every 30 minutes in routes/console.php. Run manually
 * with `php artisan cod:sync-tracking`.
 */
class SyncTrackingCommand extends Command
{
    protected $signature = 'cod:sync-tracking';

    protected $description = 'Sync delivery tracking for sent parcels, update COD order statuses and notify customers on WhatsApp';

    public function handle(TrackingSyncServiceInterface $sync): int
    {
        $counts = $sync->syncAll();

        $this->info("Tracking sync done: checked {$counts['checked']}, updated {$counts['updated']}, notified {$counts['notified']}, failed {$counts['failed']}, skipped {$counts['skipped']}.");

        if ($counts['failed'] > 0) {
            $this->warn('Some parcels failed to sync — see the parcel error_message in the admin order page or the log.');
        }

        // The command itself always succeeds when it ran to the end:
        // per-parcel provider failures are data (stored on the
        // parcel), not a command failure, so a provider outage does
        // not turn every scheduled run into a failed cron job.
        return self::SUCCESS;
    }
}
