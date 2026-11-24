<?php

namespace App\Console\Commands;

use App\Cod\Automation\AutomationSettings;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Models\CodCallLog;
use App\Models\CodOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Remind customers whose order is still waiting for confirmation,
 * and move the ones who never reply to `whatsapp_unconfirmed`.
 *
 * Reminders: an order is reminded when it is still pending
 * confirmation, its confirmation message was sent, the customer never
 * replied on WhatsApp (last_reply_at is empty — a reply, even one
 * that is not an answer, stops the reminders), fewer than
 * `max_reminders` (admin, default 1) reminders were sent, and the
 * reminder delay (admin, default 120 minutes) passed since the
 * confirmation message (first reminder) or since the previous
 * reminder. reminder_count is incremented only on a successful send,
 * so a failed attempt is retried on the next run but a sent reminder
 * is never repeated. When the automation is off, the historic
 * behaviour applies: at most one reminder per order.
 *
 * Conversion: when the automation is on, an order still pending
 * confirmation whose confirmation message was sent at least
 * `unconfirmed_after_hours` (admin, default 24) ago and whose
 * customer never replied moves to `whatsapp_unconfirmed` and stays
 * there for the team to call the customer by phone. It is NEVER
 * canceled automatically. A call-log entry (no_answer) is recorded so
 * the move shows in the order history.
 *
 * Scheduled every 15 minutes in routes/console.php. Run manually with
 * `php artisan cod:remind-unconfirmed`.
 */
class RemindUnconfirmedCodOrdersCommand extends Command
{
    protected $signature = 'cod:remind-unconfirmed';

    protected $description = 'Send WhatsApp reminders to COD orders waiting for confirmation, and move orders with no reply to whatsapp_unconfirmed';

    public function handle(WhatsAppServiceInterface $whatsapp, AutomationSettings $automation): int
    {
        $delay = $whatsapp->reminderDelayMinutes();
        $maxReminders = $automation->isEnabled() ? $automation->maxReminders() : 1;

        // Conversion first: an order past its deadline converts instead
        // of getting one more pointless reminder in the same run.
        $converted = $automation->isEnabled() ? $this->convertUnanswered($automation) : 0;
        $reminded = $this->sendDueReminders($whatsapp, $delay, $maxReminders);

        $failed = $reminded['failed'];

        $this->info("Reminded: {$reminded['sent']}, failed: {$failed}, moved to whatsapp_unconfirmed: {$converted}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Send every reminder that is due. Returns sent/failed counts.
     */
    protected function sendDueReminders(WhatsAppServiceInterface $whatsapp, int $delay, int $maxReminders): array
    {
        $query = CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNotNull('confirmation_sent_at')
            ->whereNull('last_reply_at');

        // Guarded: on an install where the automation migration has
        // not run yet there is no reminder_count column — fall back
        // to the historic one-reminder behaviour (reminder_sent_at).
        if (Schema::hasColumn('cod_orders', 'reminder_count')) {
            $query->where('reminder_count', '<', $maxReminders);
        } else {
            $query->whereNull('reminder_sent_at');
        }

        // T4: the delay is filtered in SQL (due when it passed since
        // the last contact: the confirmation message for the first
        // reminder, the previous reminder afterwards) and the orders
        // are processed in chunks instead of loading every candidate.
        $query->whereRaw('COALESCE(reminder_sent_at, confirmation_sent_at) <= ?', [now()->subMinutes($delay)]);

        $sent = 0;
        $failed = 0;

        $query->orderBy('id')->chunk(100, function ($orders) use ($whatsapp, &$sent, &$failed) {
            foreach ($orders as $order) {
                $result = $whatsapp->sendReminder($order);

                if ($result['success']) {
                    $sent++;
                    $this->info("Order {$order->external_id}: reminder sent.");
                } else {
                    $failed++;
                    $this->warn("Order {$order->external_id}: reminder failed — {$result['message']}");
                }
            }
        });

        if ($sent === 0 && $failed === 0) {
            $this->info('No unconfirmed orders due for a reminder.');
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Move still-pending orders with no reply past the conversion
     * deadline to `whatsapp_unconfirmed`. Returns how many moved.
     */
    protected function convertUnanswered(AutomationSettings $automation): int
    {
        // Guarded: without the automation migration there is no
        // whatsapp_unconfirmed_at column — skip the conversion
        // instead of crashing the scheduled command.
        if (! Schema::hasColumn('cod_orders', 'whatsapp_unconfirmed_at')) {
            $this->warn('The whatsapp_unconfirmed_at column is missing — run php artisan migrate to enable the conversion.');

            return 0;
        }

        $candidates = CodOrder::query()
            ->where('status', CodOrder::STATUS_PENDING_CONFIRMATION)
            ->whereNotNull('confirmation_sent_at')
            ->whereNull('last_reply_at')
            ->where('confirmation_sent_at', '<=', now()->subHours($automation->unconfirmedAfterHours()))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No orders due to move to whatsapp_unconfirmed.');

            return 0;
        }

        $converted = 0;

        foreach ($candidates as $order) {
            $order->status = CodOrder::STATUS_WHATSAPP_UNCONFIRMED;
            $order->whatsapp_unconfirmed_at = now();
            $order->save();

            CodCallLog::create([
                'cod_order_id' => $order->id,
                'confirmer_id' => null,
                'result' => CodCallLog::RESULT_NO_ANSWER,
                'note' => 'WhatsApp: no reply within '.$automation->unconfirmedAfterHours().'h — moved to whatsapp_unconfirmed automatically, never canceled.',
                'callback_at' => null,
                'called_at' => now(),
            ]);

            $converted++;
            $this->info("Order {$order->external_id}: moved to whatsapp_unconfirmed.");
        }

        return $converted;
    }
}
