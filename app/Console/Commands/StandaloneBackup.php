<?php

namespace App\Console\Commands;

use App\Services\Install\InstallationBackups;
use Illuminate\Console\Command;

/** Back the installation up now: database, settings and uploads in one zip. */
class StandaloneBackup extends Command
{
    protected $signature = 'standalone:backup';

    protected $description = 'Back up the database, settings and uploaded files to private storage';

    public function handle(InstallationBackups $backups): int
    {
        $made = $backups->create();
        $this->info('Backup written: '.basename($made['path']).' ('.number_format($made['size'] / 1048576, 1).' MB).');

        return self::SUCCESS;
    }
}
