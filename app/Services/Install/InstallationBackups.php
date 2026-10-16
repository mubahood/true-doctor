<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * The nightly backup of a standalone installation, and the list an owner
 * downloads from (Settings → Licence & updates).
 *
 * A standalone hospital has nobody running its server for it, so the system
 * keeps its own: one zip holding the whole database, `.env` (whose APP_KEY
 * unlocks encrypted data and the prepaid-card lookup — docs/deployment-cpanel.md),
 * the licence and every uploaded file. Seven are kept. They live on the same
 * server, which is why the page and the guide both tell the owner to download
 * one regularly and keep it somewhere else.
 */
final class InstallationBackups implements \App\Services\Backups\MakesArchive
{
    public const KEEP = 7;

    public function __construct(private readonly DatabaseBackup $database) {}

    public function directory(): string
    {
        return storage_path('app/private/backups');
    }

    /** @return array{path:string,size:int} */
    public function create(): array
    {
        $stamp = gmdate('Ymd-His');
        $work = $this->directory().'/work-'.$stamp;
        File::ensureDirectoryExists($work, 0700);
        $target = $this->directory().'/true-doctor-backup-'.$stamp.'.zip';

        try {
            $dump = $this->database->dump($work.'/database');

            $zip = new ZipArchive;
            if ($zip->open($target.'.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The backup file could not be created.');
            }
            $zip->addFile($dump, basename($dump));
            foreach (['.env' => base_path('.env'), 'licence.json' => (string) config('edition.licence_path'), 'installed.json' => (string) config('edition.installed_path')] as $name => $file) {
                if (is_file($file)) {
                    $zip->addFile($file, 'settings/'.$name);
                }
            }
            $zip->addFromString('RESTORE.txt', $this->instructions());

            // Uploads: patient documents, attachments, logos. Not the
            // backups themselves, nor update or build leftovers.
            foreach (['app/private', 'app/public'] as $folder) {
                $root = storage_path($folder);
                if (! is_dir($root)) {
                    continue;
                }
                foreach (File::allFiles($root) as $file) {
                    $relative = $folder.'/'.str_replace('\\', '/', $file->getRelativePathname());
                    if (preg_match('~^app/private/(backups|updates|system|release-builds|release-proofs|composer-home)/~', $relative)
                        || in_array(basename($relative), ['licence.json', 'installed.json', 'update.lock', 'install.lock'], true)) {
                        continue;
                    }
                    $zip->addFile($file->getPathname(), 'files/'.$relative);
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('The backup file could not be written.');
            }
            rename($target.'.tmp', $target);
            chmod($target, 0600);
        } finally {
            File::deleteDirectory($work);
            @unlink($target.'.tmp');
        }

        $this->prune();

        return ['path' => $target, 'size' => (int) filesize($target)];
    }

    /** @return list<array{name:string,size:int,created_at:int}> newest first */
    public function list(): array
    {
        $files = glob($this->directory().'/true-doctor-backup-*.zip') ?: [];
        $out = array_map(fn (string $f) => ['name' => basename($f), 'size' => (int) filesize($f), 'created_at' => (int) filemtime($f)], $files);
        usort($out, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $out;
    }

    /** A backup's path by its name, or null — never a path somebody typed. */
    public function path(string $name): ?string
    {
        if (! preg_match('/^true-doctor-backup-\d{8}-\d{6}\.zip$/', $name)) {
            return null;
        }
        $path = $this->directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    public function newestAge(): ?int
    {
        $newest = $this->list()[0] ?? null;

        return $newest === null ? null : time() - $newest['created_at'];
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            @unlink($this->directory().'/'.$old['name']);
        }
        // Update-time backups: the newest three stay, for a rollback by hand.
        $updates = glob($this->directory().'/update-*', GLOB_ONLYDIR) ?: [];
        rsort($updates);
        foreach (array_slice($updates, 3) as $old) {
            File::deleteDirectory($old);
        }
    }

    private function instructions(): string
    {
        return <<<'TXT'
True-Doctor backup

  database.sql.gz      the whole database. Restore it into an EMPTY database with
                       phpMyAdmin (Import) or: gunzip < database.sql.gz | mysql -u USER -p DATABASE
  settings/.env        the settings file. Its APP_KEY must be kept: without it,
                       encrypted data and prepaid-card lookups cannot be read.
  settings/licence.json, settings/installed.json
                       copy into true_doctor_app/storage/app/private/
  files/               uploaded documents; copy into true_doctor_app/storage/

Keep this file somewhere other than the server it came from.
TXT;
    }
}
