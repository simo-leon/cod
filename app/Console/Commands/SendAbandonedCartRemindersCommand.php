<?php

namespace App\Console\Commands;

use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Services\AbandonedCart\AbandonedCartServiceInterface;
use Illuminate\Console\Command;

/**
 * Send WhatsApp reminders for abandoned carts (idea 7).
 *
 * A cart is reminded when it is still active, still has items, was
 * last touched at least `delay_minutes` (admin, default 60) ago,
 * never became an order, and never got a reminder. The phone comes
 * from the cart (logged-in customer's phone, else the billing then
 * shipping address phone the guest typed at checkout); carts with
 * no phone, carts with only products Simo excluded from reminders,
 * and carts that became orders are skipped (logged). At most one
 * reminder per cart, ever — a successful send is recorded, a
 * failed send is retried on the next run.
 *
 * When the reminders are switched off, or WhatsApp is switched
 * off, or no WhatsApp provider is configured, the command skips
 * cleanly (nothing is sent, nothing is recorded) and exits
 * successfully — a missing token must never turn a scheduled run
 * into a failed cron job.
 *
 * Scheduled every 15 minutes in routes/console.php. Run manually
 * with `php artisan jemla:send-abandoned-cart-reminders`.
 */
class SendAbandonedCartRemindersCommand extends Command
{
    protected $signature = 'jemla:send-abandoned-cart-reminders';

    protected $description = 'Send WhatsApp reminders for carts abandoned without ordering';

    public function handle(AbandonedCartServiceInterface $abandoned, WhatsAppServiceInterface $whatsapp): int
    {
        if (! $abandoned->isEnabled()) {
            $this->info('Abandoned-cart reminders are disabled in the admin (Configuration → General → Store Features → Abandoned Cart Reminders).');

            return self::SUCCESS;
        }

        if (! $whatsapp->isEnabled()) {
            $this->warn('COD WhatsApp is disabled in the admin — no reminders sent. Enable it at Configuration → General → COD System → WhatsApp.');

            return self::SUCCESS;
        }

        if ($whatsapp->isEnabledWithoutRealProvider()) {
            $this->warn('No WhatsApp provider is configured — no reminders sent. Choose Meta or Evolution and fill its credentials at Configuration → General → COD System → WhatsApp.');

            return self::SUCCESS;
        }

        $carts = $abandoned->dueCarts();

        if ($carts->isEmpty()) {
            $this->info('No abandoned carts due for a reminder.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($carts as $cart) {
            $result = $abandoned->processCart($cart);

            match ($result['status']) {
                'sent' => $sent++,
                'skipped' => $skipped++,
                default => $failed++,
            };

            $label = match ($result['status']) {
                'sent' => 'reminder sent',
                'skipped' => 'skipped',
                default => 'failed',
            };

            $this->info("Cart {$cart->id}: {$label} — {$result['message']}");
        }

        $this->info("Sent: {$sent}, skipped: {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
