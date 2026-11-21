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
