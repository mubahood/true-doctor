<?php

namespace App\Console\Commands;

use App\Services\Backups\OffsiteBackup;
use App\Support\Backups\BackupCipher;
use Illuminate\Console\Command;

/** Back the online service up now: encrypted, off the server, pruned. */
class TdBackup extends Command
{
    protected $signature = 'td:backup {--new-key : Print a new BACKUP_ENCRYPTION_KEY and do nothing else}';

    protected $description = 'Encrypted backup of the database, settings and documents, sent to off-site storage';

    public function handle(OffsiteBackup $backup): int
    {
        if ($this->option('new-key')) {
            $this->line('BACKUP_ENCRYPTION_KEY='.BackupCipher::newKey());
            $this->warn('Keep a copy of this key away from this server. Without it no backup can be opened.');

            return self::SUCCESS;
        }

        $made = $backup->run();

        $this->info(sprintf(
            'Backup made: %s (%s MB)%s.',
            $made['object'] ?? $made['local'],
            number_format($made['size'] / 1048576, 1),
            $made['offsite'] ? ($made['monthly'] ? ', kept as this month\'s copy too' : '') : ', kept on this server only: no off-site store is set (BACKUP_S3_*)',
        ));

        return self::SUCCESS;
    }
}
