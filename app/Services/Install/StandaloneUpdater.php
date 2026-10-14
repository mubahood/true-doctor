<?php

namespace App\Services\Install;

use App\Services\Releases\StandaloneArchive;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Moves a standalone installation to a newer release, and back again if
 * anything goes wrong (docs/proposals/standalone-edition.md §9).
 *
 * It is deploy/deploy.sh for a buyer's server, with the same habit of proving
 * itself before it hands the site back:
 *
 *   verify      the package's signature against the keys this installation
 *               already trusts, every file's hash, that it is newer, and that
 *               the licence's update entitlement covers it
 *   preflight   disk space and writable folders
 *   down        maintenance mode
 *   back up     the database, .env, the licence, and every code file the
 *               update will touch
 *   swap        copy the new files in by the signed manifest; remove the
 *               files the new release no longer has
 *   migrate     in a fresh PHP process, on the new code: migrations, the
 *               reference seeders, the books
 *   prove       a health check in a fresh process
 *   up
 *
 * A failure after the swap began puts the code and the database back exactly
 * as they were and brings the site up on the old release.
 *
 * Runs only from the command line (the scheduler, or a technician): a web
 * request may be cut off half way through copying files, and the PHP binary a
 * web server runs is not one that can run artisan.
 */
final class StandaloneUpdater
{
    /** @var list<string> */
    private array $log = [];

    private readonly string $appRoot;

    private readonly string $webRoot;

    /**
     * The roots default to this installation's own; a test passes temporary
     * folders so it can never write into the application it runs from.
     */
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly SystemState $state,
        private readonly ArtisanRunner $artisan,
        ?string $appRoot = null,
        ?string $webRoot = null,
    ) {
        $this->appRoot = rtrim($appRoot ?? base_path(), '/');
        $this->webRoot = rtrim($webRoot ?? public_path(), '/');
    }

    /**
     * Check a package without changing anything.
     *
     * @return array{version:string,built_at:string,files:int,size:int}
     */
    public function inspect(string $archive): array
    {
        if (! Edition::isStandalone()) {
            throw new \DomainException('Only a standalone installation updates itself.');
        }
        if (! is_file($archive)) {
            throw new \DomainException('The update file is missing.');
        }

        $keys = config('edition.public_keys', []);
        if (! is_array($keys) || $keys === []) {
            throw new \DomainException('This installation has no trusted release keys, so it cannot verify an update.');
        }

        try {
            $manifest = (new StandaloneArchive)->verify($archive, new Verifier($keys));
        } catch (\RuntimeException $e) {
            throw new \DomainException('This is not a genuine True-Doctor update: '.$e->getMessage());
        }

        $release = $this->readFromArchive($archive, 'true_doctor_app/RELEASE.json');
        $builtAt = is_string($release['built_at'] ?? null) ? $release['built_at'] : null;
        $version = (string) $manifest['version'];

        $current = Edition::version();
        if ($current !== 'dev' && version_compare($version, $current, '<=')) {
            throw new \DomainException("This installation already runs {$current}; the update is {$version}.");
        }

        $licence = app(InstalledLicence::class);
        $until = $licence->updatesUntil();
        if (! $licence->isValid() || $until === null) {
            throw new \DomainException('A valid licence is needed to install updates. Patient care is unaffected.');
        }
        if ($builtAt === null || Carbon::parse($builtAt)->greaterThan($until->copy()->endOfDay())) {
            throw new \DomainException('This release was made after your update entitlement ended on '.$until->format('d M Y').'. Renew updates in the buyer portal.');
        }

        return ['version' => $version, 'built_at' => $builtAt, 'files' => count($manifest['files']), 'size' => (int) filesize($archive)];
    }

    /**
     * Apply a verified package.
     *
     * @return array{from:string,to:string,backup:string}
     */
    public function apply(string $archive, ?int $by = null): array
    {
        if (! app()->runningInConsole()) {
            throw new \DomainException('Updates run from the scheduler or the command line, never inside a web request.');
        }

        $private = $this->appRoot.'/storage/app/private';
        \Illuminate\Support\Facades\File::ensureDirectoryExists($private, 0700);
        $lock = fopen($private.'/update.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \DomainException('Another update is already running.');
        }

        $from = Edition::version();
        $stamp = gmdate('Ymd-His');
        $backupDir = $private.'/backups/update-'.$stamp;
        $extract = $private.'/updates/extract-'.$stamp;
        $swapped = false;
        $migrated = false;
        $dump = null;
        $snapshot = [];
        $newManifest = ['files' => []];

        try {
            $this->step('Checking the update package');
            $checked = $this->inspect($archive);
            $to = $checked['version'];
            $this->state->running('update', "Updating {$from} → {$to}", $by);

            $this->step('Checking free disk space');
            $free = @disk_free_space($this->appRoot);
            if ($free !== false && $free < 3 * $checked['size'] + 100 * 1024 * 1024) {
                throw new \DomainException('Not enough free disk space for a safe update (needs about '.round((3 * $checked['size'] + 100 * 1024 * 1024) / 1048576).' MB).');
            }
            foreach ([$this->appRoot, $this->webRoot, $this->appRoot.'/storage'] as $folder) {
                if (! is_writable($folder)) {
                    throw new \DomainException("This folder is not writable: {$folder}");
                }
            }

            $this->step('Extracting the package');
            File::ensureDirectoryExists($extract, 0700);
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true || ! $zip->extractTo($extract)) {
                throw new \RuntimeException('The package could not be extracted.');
            }
            $zip->close();
            $newManifest = $this->manifestAt($extract.'/PACKAGE.json');
            $oldManifest = $this->manifestAt(dirname($this->appRoot).'/PACKAGE.json');

            $this->step('Switching to maintenance mode');
            $this->artisan->down();

            $this->step('Backing up the database');
            File::ensureDirectoryExists($backupDir, 0700);
            $dump = $this->backup->dump($backupDir.'/database');
            foreach (['.env' => $this->appRoot.'/.env', 'licence.json' => (string) config('edition.licence_path'), 'installed.json' => (string) config('edition.installed_path')] as $name => $file) {
                if (is_file($file)) {
                    copy($file, $backupDir.'/'.$name);
                }
            }

            $this->step('Backing up the code the update replaces');
            $touched = array_unique([...array_keys($newManifest['files']), ...array_keys($oldManifest['files'])]);
            foreach ($touched as $path) {
                $target = $this->target($path);
                if ($target !== null && is_file($target)) {
                    $copy = $backupDir.'/code/'.$path;
                    File::ensureDirectoryExists(dirname($copy), 0700);
                    copy($target, $copy);
                    $snapshot[$path] = $copy;
                }
            }
            if (is_file(dirname($this->appRoot).'/PACKAGE.json')) {
                copy(dirname($this->appRoot).'/PACKAGE.json', $backupDir.'/PACKAGE.json');
            }

            $this->step('Installing the new files');
            $swapped = true;
            foreach ($newManifest['files'] as $path => $expected) {
                $target = $this->target($path);
                if ($target === null) {
                    continue;
                }
                $source = $extract.'/'.$path;
                File::ensureDirectoryExists(dirname($target));
                $temporary = $target.'.td-update';
                if (! copy($source, $temporary) || ! rename($temporary, $target)) {
                    throw new \RuntimeException("Could not write {$path}.");
                }
            }
            foreach (array_diff(array_keys($oldManifest['files']), array_keys($newManifest['files'])) as $gone) {
                $target = $this->target($gone);
                if ($target !== null && is_file($target)) {
                    unlink($target);
                }
            }
            copy($extract.'/PACKAGE.json', dirname($this->appRoot).'/PACKAGE.json');
            $this->clearCompiledFiles();

            $this->step('Running database migrations on the new release');
            $migrated = true;
            $this->artisan->fresh($this->appRoot, ['migrate', '--force']);
            $this->artisan->fresh($this->appRoot, ['db:seed', '--class=Database\\Seeders\\StandaloneReferenceSeeder', '--force']);
            $this->artisan->fresh($this->appRoot, ['finance:backfill', '--no-interaction']);

            $this->step('Checking the updated system');
            $this->artisan->fresh($this->appRoot, ['standalone:health']);

            $this->recordInstalledVersion($to);
            $this->artisan->up();
            $this->step("Updated to {$to}");
            $this->state->finished('update', "Updated {$from} → {$to}", $this->log);

            return ['from' => $from, 'to' => $to, 'backup' => $backupDir];
        } catch (\Throwable $e) {
            $this->step('Stopped: '.$e->getMessage());

            if ($swapped) {
                $this->step('Putting the previous release back');
                $this->rollbackCode($snapshot, $newManifest, $backupDir);
            }
            if ($migrated && is_string($dump)) {
                $this->step('Restoring the database from the backup');
                try {
                    $this->backup->restore($dump);
                } catch (\Throwable $restore) {
                    $this->step('The database could not be restored automatically: '.$restore->getMessage().' — restore '.$dump.' by hand.');
                }
            }

            $this->artisan->up();
            $this->state->failed('update', $e->getMessage(), $this->log);

            throw $e;
        } finally {
            File::deleteDirectory($extract);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Where a package path lives on this server, or null for anything outside the two managed trees. */
    private function target(string $path): ?string
    {
        if (str_starts_with($path, 'true_doctor_app/')) {
            $inside = substr($path, strlen('true_doctor_app/'));
            // Never the buyer's own data or settings.
            if ($inside === '.env' || str_starts_with($inside, 'storage/')) {
                return null;
            }

            return $this->appRoot.'/'.$inside;
        }
        if (str_starts_with($path, 'web_root_files/')) {
            $inside = substr($path, strlen('web_root_files/'));

            return $inside === '' || str_starts_with($inside, 'storage/') ? null : $this->webRoot.'/'.$inside;
        }

        return null;
    }

    /**
     * @param  array<string,string>  $snapshot  package path => saved copy
     * @param  array{files:array<string,mixed>}  $newManifest
     */
    private function rollbackCode(array $snapshot, array $newManifest, string $backupDir): void
    {
        // Files the new release added that were not there before go.
        foreach (array_keys($newManifest['files']) as $path) {
            $target = $this->target($path);
            if ($target !== null && ! isset($snapshot[$path]) && is_file($target)) {
                @unlink($target);
            }
        }
        // Everything that was there comes back as it was.
        foreach ($snapshot as $path => $copy) {
            $target = $this->target($path);
            if ($target !== null) {
                File::ensureDirectoryExists(dirname($target));
                @copy($copy, $target);
            }
        }
        if (is_file($backupDir.'/PACKAGE.json')) {
            @copy($backupDir.'/PACKAGE.json', dirname($this->appRoot).'/PACKAGE.json');
        }
        $this->clearCompiledFiles();
    }

    /** Cached config, routes, events and packages belong to the code that wrote them. */
    private function clearCompiledFiles(): void
    {
        foreach (glob($this->appRoot.'/bootstrap/cache/*.php') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->appRoot.'/storage/framework/views/*.php') ?: [] as $file) {
            @unlink($file);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** @return array{files:array<string,mixed>} */
    private function manifestAt(string $file): array
    {
        if (! is_file($file)) {
            return ['files' => []];
        }
        $signed = json_decode((string) file_get_contents($file), true);
        $files = is_array($signed['manifest']['files'] ?? null) ? $signed['manifest']['files'] : [];

        return ['files' => $files];
    }

    /** @return array<string,mixed> */
    private function readFromArchive(string $archive, string $name): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            return [];
        }
        try {
            $data = json_decode((string) $zip->getFromName($name), true);

            return is_array($data) ? $data : [];
        } finally {
            $zip->close();
        }
    }

    private function recordInstalledVersion(string $version): void
    {
        $marker = (string) config('edition.installed_path');
        $state = is_file($marker) ? (json_decode((string) file_get_contents($marker), true) ?: []) : [];
        $state['version'] = $version;
        $state['updated_at'] = now()->toIso8601String();
        File::put($marker.'.tmp', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($marker.'.tmp', 0600);
        rename($marker.'.tmp', $marker);
    }

    private function step(string $message): void
    {
        $this->log[] = now()->format('H:i:s').'  '.$message;
        $this->state->progress($message, $this->log);
    }
}
