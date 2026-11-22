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
 * COD System (phase 4): remind customers whose order is still waiting
 * for confirmation. Runs every 15 minutes; the command itself only
 * reminds orders whose confirmation was sent at least
 * `reminder_delay_minutes` (default 120) ago, and only once per order.
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
