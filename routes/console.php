<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Webkul\Sitemap\Jobs\ProcessSitemap;
use Webkul\Sitemap\Models\Sitemap;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/**
 * Automatically generate and refresh all XML sitemaps.
 */
Artisan::command('bagisto:sitemap:generate', function () {
    $this->info('Starting automated sitemap generation...');

    $sitemaps = Sitemap::all();

    if ($sitemaps->isEmpty()) {
        $this->info('No sitemaps configured. Creating default sitemap.xml...');

        $sitemap = Sitemap::create([
            'file_name' => 'sitemap.xml',
            'path' => '/',
            'additional' => [],
        ]);

        $sitemaps = collect([$sitemap]);
    }

    foreach ($sitemaps as $sitemap) {
        $this->info("Processing sitemap: {$sitemap->file_name} (ID: {$sitemap->id})");

        ProcessSitemap::dispatchSync($sitemap);
    }

    $this->info('Sitemap generation completed successfully.');
})->purpose('Generate and update all XML sitemaps automatically');

Schedule::command('bagisto:sitemap:generate')->dailyAt('02:00');

/**
 * COD System (phase 2): import new cash-on-delivery store orders into
 * the COD System every hour. The command is idempotent, so a missed or
 * repeated run never creates duplicates.
 */
Schedule::command('cod:import-orders')->hourly();

/**
 * COD System: remind customers whose order is still waiting for
 * confirmation, and move the ones who never reply to
 * `whatsapp_unconfirmed`. Runs every 15 minutes; the command itself
 * only reminds orders whose confirmation was sent at least
 * `reminder_delay_minutes` (default 120) ago and at most
 * `max_reminders` times (default 1), and only converts orders whose
 * confirmation was sent at least `unconfirmed_after_hours` (default
 * 24) ago with no reply at all.
 */
Schedule::command('cod:remind-unconfirmed')->everyFifteenMinutes();

/**
 * COD System (phase 6): sync delivery tracking for sent parcels,
 * update the linked COD order statuses and notify customers on
 * WhatsApp. Runs every 30 minutes, not 15: delivery statuses change
 * on the scale of hours, not minutes, and every run costs one API
 * call per open parcel against the providers' rate limits (Vitis
 * Express allows 1000 requests/hour). The command is idempotent, so
 * a missed or repeated run changes nothing.
 */
Schedule::command('cod:sync-tracking')->everyThirtyMinutes();

/**
 * Souk (market sourcing): safety net for the automatic zero-stock
 * assignment — catches stock changes made any other way than a
 * checkout or a product edit (imports, manual edits, refunds).
 * Runs every 30 minutes; the command is idempotent.
 */
Schedule::command('souk:check-zero-stock')->everyThirtyMinutes();

/**
 * Souk (market sourcing): expire unclaimed tasks past their claim
 * deadline (recreating them so the product stays available) and
 * flag stalled claimed tasks as escalated. Runs hourly.
 */
Schedule::command('souk:escalate')->hourly();

/**
 * Store features (idea 7): send the WhatsApp reminder for carts
 * abandoned without ordering. Runs every 15 minutes; the command
 * itself only reminds carts that were last touched at least
 * `delay_minutes` (admin, default 60) ago, and at most once per
 * cart, ever. When the reminders are off, WhatsApp is off, or no
 * provider is configured, the run skips cleanly.
 */
Schedule::command('jemla:send-abandoned-cart-reminders')->everyFifteenMinutes();
