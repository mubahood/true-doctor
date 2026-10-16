<?php

namespace App\Services\Backups;

use App\Support\Backups\BackupCipher;
use App\Support\Backups\S3Client;
use App\Support\Settings;
use Illuminate\Support\Facades\File;

/**
 * The online service's nightly backup: the same archive a standalone copy
 * makes (database, settings, uploaded documents), encrypted, sent off this
 * server, and pruned to 30 daily and 12 monthly copies (config/backup.php).
 *
 * Without a store configured it still makes the encrypted copy here and says
 * so, so the health check can warn that nothing is off the server yet.
 */
class OffsiteBackup
{
    public const LOCAL_KEEP = 3;

    public function __construct(private readonly MakesArchive $archives) {}

    public function localDirectory(): string
    {
        return storage_path('app/private/backups/offsite');
    }

    /** @return array{object:?string, size:int, offsite:bool, monthly:bool, local:string} */
    public function run(): array
    {
        $key = BackupCipher::key();
        if ($key === null) {
            throw new \RuntimeException('BACKUP_ENCRYPTION_KEY is not set (32 bytes, base64). Run `php artisan td:backup --new-key` and keep the key away from this server.');
        }

        $made = $this->archives->create();
        File::ensureDirectoryExists($this->localDirectory(), 0700);
        $stamp = gmdate('Ymd-His');
        $encrypted = $this->localDirectory().'/true-doctor-'.$stamp.'.tdbak';

        try {
            BackupCipher::encrypt($made['path'], $encrypted, $key);
        } finally {
            // The plain archive never stays on the server: only the sealed one does.
            @unlink($made['path']);
        }

        $result = ['object' => null, 'size' => (int) filesize($encrypted), 'offsite' => false, 'monthly' => false, 'local' => basename($encrypted)];

        $store = S3Client::fromConfig();
        if ($store !== null) {
            $prefix = trim((string) config('backup.s3.prefix', 'true-doctor'), '/');
            $object = "{$prefix}/daily/true-doctor-{$stamp}.tdbak";
            $store->put($object, $encrypted);
            $result['object'] = $object;
            $result['offsite'] = true;

            $monthly = "{$prefix}/monthly/true-doctor-".gmdate('Y-m').'.tdbak';
            if (! collect($store->list("{$prefix}/monthly/"))->contains('key', $monthly)) {
                $store->put($monthly, $encrypted);
                $result['monthly'] = true;
            }

            $this->prune($store, "{$prefix}/daily/", (int) config('backup.keep_daily', 30));
            $this->prune($store, "{$prefix}/monthly/", (int) config('backup.keep_monthly', 12));
        }

        $this->pruneLocal();

        Settings::set('backup_last', json_encode(['at' => now()->toIso8601String()] + $result, JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /** @return array<string,mixed>|null what the last run said */
    public static function last(): ?array
    {
        $raw = Settings::get('backup_last');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /** The newest encrypted copy kept on this server. */
    public function newestLocal(): ?string
    {
        $files = glob($this->localDirectory().'/true-doctor-*.tdbak') ?: [];
        rsort($files);

        return $files[0] ?? null;
    }

    private function prune(S3Client $store, string $prefix, int $keep): void
    {
        $objects = $store->list($prefix);
        foreach (array_slice($objects, 0, max(0, count($objects) - $keep)) as $old) {
            $store->delete($old['key']);
        }
    }

    private function pruneLocal(): void
    {
        $files = glob($this->localDirectory().'/true-doctor-*.tdbak') ?: [];
        rsort($files);
        foreach (array_slice($files, self::LOCAL_KEEP) as $old) {
            @unlink($old);
        }
    }
}
