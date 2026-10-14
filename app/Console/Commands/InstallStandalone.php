<?php

namespace App\Console\Commands;

use App\Services\Install\StandaloneInstaller;
use Illuminate\Console\Command;

/** CLI path uses the same engine as the web installer; no secret on argv. */
class InstallStandalone extends Command
{
    protected $signature = 'standalone:install {--config= : Path to a private JSON settings file}';

    protected $description = 'Complete a standalone installation after .env and licence.json are present';

    public function handle(StandaloneInstaller $installer): int
    {
        $path = $this->option('config');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            $this->error('Provide a readable private JSON settings file.');

            return self::FAILURE;
        }
        $details = json_decode((string) file_get_contents($path), true);
        if (! is_array($details) || array_diff(['hospital_name', 'owner_name', 'owner_email', 'owner_password', 'currency', 'timezone'], array_keys($details)) !== []) {
            $this->error('The installation settings file is incomplete.');

            return self::FAILURE;
        }
        foreach ($details as $value) {
            if (! is_string($value)) {
                $this->error('Installation settings must be strings.');

                return self::FAILURE;
            }
        }

        $installer->install($details);
        $this->info('Standalone installation complete.');

        return self::SUCCESS;
    }
}
