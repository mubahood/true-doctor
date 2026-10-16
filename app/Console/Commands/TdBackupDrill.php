<?php

namespace App\Console\Commands;

use App\Services\Backups\RestoreDrill;
use Illuminate\Console\Command;

/** Prove the newest backup restores: decrypt, unpack, restore into a scratch database, compare. */
class TdBackupDrill extends Command
{
    protected $signature = 'td:backup-drill';

    protected $description = 'Restore the newest backup into a scratch database and compare it with live';

    public function handle(RestoreDrill $drill): int
    {
        $result = $drill->run();

        $this->line(($result['ok'] ? 'PASSED' : 'FAILED')." ({$result['mode']}) from {$result['source']}: {$result['tables']} tables.");
        foreach ($result['problems'] as $problem) {
            $this->error('  '.$problem);
        }
        foreach ($result['notes'] as $note) {
            $this->line('  '.$note);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
