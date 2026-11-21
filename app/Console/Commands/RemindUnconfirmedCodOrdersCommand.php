<?php

namespace App\Console\Commands;

use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\CodOrder;
use Illuminate\Console\Command;

/**
 * Send one reminder to every COD order still waiting for confirmation
 * (phase 4).
 *
 * An order is reminded when: it is still pending confirmation, its
 * confirmation message was sent at least `reminder_delay_minutes`
 * (admin, default 120) ago, no reminder was sent yet, and the customer
 * never replied on WhatsApp (last_reply_at is empty — a reply, even
 * one that is not an answer, stops the reminder). Exactly one reminder
 * per order: reminder_sent_at is set only on a successful send, so a
 * failed attempt is retried on the next run but a sent reminder is
 * never repeated.
 *
 * Scheduled every 15 minutes in routes/console.php. Run manually with
 * `php artisan cod:remind-unconfirmed`.
 */
class RemindUnconfirmedCodOrdersCommand extends Command
{
    protected $signature = 'cod:remind-unconfirmed';

    protected $description = 'Send a WhatsApp reminder to COD orders waiting for confirmation for more than the configured delay';

    public function handle(WhatsAppServiceInterface $whatsapp): int
    {
        $delay = $whatsapp->reminderDelayMinutes();

        $candidates = CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNotNull('confirmation_sent_at')
            ->whereNull('reminder_sent_at')
            ->whereNull('last_reply_at')
            ->where('confirmation_sent_at', '<=', now()->subMinutes($delay))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unconfirmed orders due for a reminder.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($candidates as $order) {
            $result = $whatsapp->sendReminder($order);

            if ($result['success']) {
                $sent++;
                $this->info("Order {$order->external_id}: reminder sent.");
            } else {
                $failed++;
                $this->warn("Order {$order->external_id}: reminder failed — {$result['message']}");
            }
        }

        $this->info("Reminded: {$sent}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
