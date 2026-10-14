<?php

namespace App\Console\Commands;

use App\Services\Install\StandaloneUpdater;
use App\Services\Install\UpdateChannel;
use Illuminate\Console\Command;

/**
 * Update a standalone installation from the command line — a downloaded
 * package, or the newest one the licence may fetch online. The web page does
 * the same through the scheduler (standalone:system-requests).
 */
class StandaloneUpdate extends Command
{
    protected $signature = 'standalone:update {archive? : Path to a true-doctor-standalone-*.zip} {--check : Only say whether an update is available} {--online : Download the newest entitled release and install it}';

    protected $description = 'Verify and install a newer standalone release, with a backup and automatic rollback';

    public function handle(StandaloneUpdater $updater, UpdateChannel $channel): int
    {
        try {
            if ($this->option('check') || $this->option('online')) {
                $latest = $channel->check();
                if ($latest === null) {
                    $this->info('This installation is up to date.');

                    return self::SUCCESS;
                }
                $this->info("True-Doctor {$latest['version']} is available.");
                if ($this->option('check')) {
                    return self::SUCCESS;
                }
                $archive = $channel->download($latest['download_url'], $latest['sha256']);
            } else {
                $archive = (string) $this->argument('archive');
                if ($archive === '') {
                    $this->error('Give the path to an update file, or use --online.');

                    return self::FAILURE;
                }
            }

            $checked = $updater->inspect($archive);
            $this->line("Verified {$checked['version']} ({$checked['files']} files). Updating — the site is in maintenance mode until it finishes.");
            $result = $updater->apply($archive);
            $this->info("Updated {$result['from']} → {$result['to']}. Backup: {$result['backup']}");

            return self::SUCCESS;
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('The update stopped and the previous release was put back: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
