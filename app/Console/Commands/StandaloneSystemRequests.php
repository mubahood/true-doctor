<?php

namespace App\Console\Commands;

use App\Services\Install\InstallationBackups;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Services\Install\UpdateChannel;
use Illuminate\Console\Command;

/**
 * Carry out what Settings → Licence & updates asked for: an update or a
 * backup. Scheduled every minute in the standalone edition, so the web page
 * never runs long work inside a request.
 */
class StandaloneSystemRequests extends Command
{
    protected $signature = 'standalone:system-requests';

    protected $description = 'Run the update or backup requested from the Licence & updates page';

    public function handle(SystemState $state, StandaloneUpdater $updater, UpdateChannel $channel, InstallationBackups $backups): int
    {
        $request = $state->claim();
        if ($request === null) {
            return self::SUCCESS;
        }

        $by = is_int($request['by'] ?? null) ? $request['by'] : null;

        try {
            switch ($request['action'] ?? null) {
                case 'backup':
                    $state->running('backup', 'Backing up…', $by);
                    $made = $backups->create();
                    $state->finished('backup', 'Backup ready: '.basename($made['path']));
                    break;

                case 'update':
                    $state->running('update', 'Preparing the update…', $by);
                    $archive = $request['archive'] ?? null;
                    if (($request['source'] ?? null) === 'online') {
                        $latest = $channel->check();
                        if ($latest === null) {
                            $state->finished('update', 'Already up to date.');
                            break;
                        }
                        $archive = $channel->download($latest['download_url'], $latest['sha256']);
                    }
                    if (! is_string($archive) || ! is_file($archive)) {
                        throw new \DomainException('The update file is missing. Upload it again.');
                    }
                    try {
                        $updater->apply($archive, $by);
                    } finally {
                        if (str_starts_with(realpath($archive) ?: '', storage_path('app/private/updates'))) {
                            @unlink($archive);
                        }
                    }
                    break;

                default:
                    $state->failed('unknown', 'An unknown request was ignored.');
            }
        } catch (\Throwable $e) {
            // The updater has already written its own failure with the log;
            // anything that failed before it started is recorded here.
            if (($state->current()['status'] ?? null) !== 'failed') {
                $state->failed((string) ($request['action'] ?? 'request'), $e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
