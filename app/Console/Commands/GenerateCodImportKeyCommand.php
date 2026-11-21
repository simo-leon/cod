<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates the secret key of the COD import endpoint and stores it in
 * the admin settings (core_config: general.cod_system.settings.
 * import_secret_key, default channel).
 *
 * The key is printed once so it can be copied to the system that calls
 * POST /api/cod/orders. It is never written to a file or to the code.
 * The same key can also be set or rotated by hand in Admin →
 * Configuration → General → COD System → Import Secret Key. Use --show
 * to check whether a key is configured without regenerating it.
 */
class GenerateCodImportKeyCommand extends Command
{
    protected $signature = 'cod:generate-import-key {--show : Show whether an import key is configured, without regenerating it}';

    protected $description = 'Generate and store the secret key of the COD import endpoint (POST /api/cod/orders)';

    public const CONFIG_CODE = 'general.cod_system.settings.import_secret_key';

    public function handle(): int
    {
        if ($this->option('show')) {
            $exists = DB::table('core_config')
                ->where('code', self::CONFIG_CODE)
                ->whereNotNull('value')
                ->where('value', '<>', '')
                ->exists();

            $this->info($exists
                ? 'An import secret key is configured.'
                : 'No import secret key is configured. Run this command without --show to generate one.');

            return self::SUCCESS;
        }

        $key = Str::random(64);

        DB::table('core_config')->updateOrInsert(
            ['code' => self::CONFIG_CODE, 'channel_code' => 'default', 'locale_code' => ''],
            ['value' => $key, 'created_at' => now(), 'updated_at' => now()],
        );

        // CoreConfig values are cached (file cache): without this, the
        // endpoint would keep reading the old (empty) key until a manual
        // cache:clear, and every request would return 401.
        Artisan::call('cache:clear');

        $this->info('Import secret key generated and stored in the COD System settings.');
        $this->line('');
        $this->line('Send it as the X-COD-Import-Key header (or Bearer token) to POST /api/cod/orders:');
        $this->line('');
        $this->line($key);
        $this->line('');
        $this->warn('Copy it now: it is shown only once. To rotate it, run this command again.');

        return self::SUCCESS;
    }
}
