<?php

namespace App\Services\Backups;

use App\Services\Install\DatabaseBackup;
use App\Support\Backups\BackupCipher;
use App\Support\Backups\S3Client;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * A backup that was never restored does not count. Once a month this takes
 * the newest backup (from the off-site store, else this server), decrypts
 * it, unpacks it and restores the database into a scratch database, then
 * compares it with the live one: every live table present, row counts close,
 * and the first rows of the tables that matter identical.
 *
 * With no scratch database configured it still opens, decrypts and reads the
 * whole dump (every statement, every table), and says that it could not go
 * further.
 */
class RestoreDrill
{
    public const CONNECTION = 'backup_drill';

    /** Tables whose oldest rows must match exactly (they are never edited in bulk). */
    private const CHECKED = ['hospitals' => ['id', 'name'], 'users' => ['id', 'email'], 'patients' => ['id', 'uuid']];

    public function __construct(
        private readonly OffsiteBackup $offsite,
        private readonly DatabaseBackup $database,
    ) {}

    /** @return array{ok:bool, mode:string, source:string, tables:int, problems:list<string>, notes:list<string>} */
    public function run(): array
    {
        $key = BackupCipher::key();
        if ($key === null) {
            return $this->finish(false, 'none', '-', 0, ['BACKUP_ENCRYPTION_KEY is not set, so no backup can be opened.'], []);
        }

        $work = storage_path('app/private/backups/drill-'.gmdate('Ymd-His'));
        File::ensureDirectoryExists($work, 0700);

        try {
            [$encrypted, $source] = $this->fetch($work);
            BackupCipher::decrypt($encrypted, $work.'/backup.zip', $key);

            $zip = new ZipArchive;
            if ($zip->open($work.'/backup.zip') !== true) {
                return $this->finish(false, 'none', $source, 0, ['The decrypted backup is not a readable archive.'], []);
            }
            $dumpName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('/^database\.(sql\.gz|sqlite)$/', $name)) {
                    $dumpName = $name;
                }
            }
            if ($dumpName === null) {
                $zip->close();

                return $this->finish(false, 'none', $source, 0, ['The backup has no database in it.'], []);
            }
            $zip->extractTo($work, [$dumpName]);
            $zip->close();
            $dump = $work.'/'.$dumpName;

            return $this->drillDatabaseConfigured()
                ? $this->restoreAndCompare($dump, $source)
                : $this->readOnly($dump, $source);
        } catch (\Throwable $e) {
            return $this->finish(false, 'none', $source ?? '-', 0, [$e->getMessage()], []);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @return array<string,mixed>|null */
    public static function last(): ?array
    {
        $raw = Settings::get('backup_drill_last');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /** @return array{0:string,1:string} the encrypted file and where it came from */
    private function fetch(string $work): array
    {
        $store = S3Client::fromConfig();
        if ($store !== null) {
            $prefix = trim((string) config('backup.s3.prefix', 'true-doctor'), '/');
            $objects = $store->list("{$prefix}/daily/");
            $newest = end($objects);
            if ($newest !== false) {
                $store->get($newest['key'], $work.'/backup.tdbak');

                return [$work.'/backup.tdbak', 'store: '.$newest['key']];
            }
        }

        $local = $this->offsite->newestLocal();
        if ($local === null) {
            throw new \RuntimeException('There is no backup to test: none in the store and none on this server.');
        }

        return [$local, 'this server: '.basename($local)];
    }

    private function drillDatabaseConfigured(): bool
    {
        return filled(config('backup.drill.database')) && filled(config('backup.drill.username'));
    }

    private function restoreAndCompare(string $dump, string $source): array
    {
        $drill = (array) config('backup.drill');
        $live = DB::connection()->getConfig();
        if (($drill['database'] ?? null) === ($live['database'] ?? null) && ($drill['host'] ?? null) === ($live['host'] ?? null)) {
            return $this->finish(false, 'restore', $source, 0, ['The scratch database is the live database. Refusing to restore over it.'], []);
        }
        if (! str_ends_with($dump, '.sql.gz')) {
            return $this->finish(false, 'restore', $source, 0, ['Only MySQL backups can be restored into the scratch database.'], []);
        }

        config(['database.connections.'.self::CONNECTION => array_merge((array) config('database.connections.'.DB::getDefaultConnection()), [
            'host' => $drill['host'], 'port' => $drill['port'], 'database' => $drill['database'],
            'username' => $drill['username'], 'password' => $drill['password'], 'unix_socket' => (string) ($drill['socket'] ?? ''),
        ])]);
        DB::purge(self::CONNECTION);

        try {
            $this->database->restore($dump, self::CONNECTION);

            $problems = [];
            $notes = [];
            // Keyed by lower case: MySQL on some systems (macOS, Windows) lowers table names.
            $liveTables = $this->byLowerName($this->tables(null));
            $drillTables = $this->byLowerName($this->tables(self::CONNECTION));
            foreach (array_diff_key($liveTables, $drillTables) as $missing) {
                $problems[] = "The live table {$missing} is not in the backup.";
            }

            foreach (array_intersect_key($liveTables, $drillTables) as $lower => $table) {
                $then = (int) DB::connection(self::CONNECTION)->table($drillTables[$lower])->count();
                $now = (int) DB::table($table)->count();
                if ($now > 100 && $then < $now * 0.5) {
                    $problems[] = "{$table}: the backup has {$then} rows, live has {$now}.";
                } elseif ($then !== $now) {
                    $notes[] = "{$table}: {$then} then, {$now} now.";
                }
            }

            foreach (self::CHECKED as $table => $columns) {
                if (! isset($drillTables[$table])) {
                    continue;
                }
                $then = DB::connection(self::CONNECTION)->table($table)->orderBy('id')->limit(50)->get($columns)->map(fn ($r) => (array) $r)->all();
                $now = DB::table($table)->whereIn('id', array_column($then, 'id'))->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();
                if (sha1(json_encode($then)) !== sha1(json_encode($now))) {
                    $notes[] = "{$table}: some of the oldest rows differ from live (edited since the backup?).";
                }
            }

            return $this->finish($problems === [], 'restore', $source, count($drillTables), $problems, array_slice($notes, 0, 20));
        } finally {
            $this->empty(self::CONNECTION);
            DB::purge(self::CONNECTION);
        }
    }

    /** No scratch database: read every statement of the dump and check every live table is created in it. */
    private function readOnly(string $dump, string $source): array
    {
        if (! str_ends_with($dump, '.sql.gz')) {
            $ok = filesize($dump) > 0;

            return $this->finish($ok, 'read', $source, 0, $ok ? [] : ['The database file is empty.'], ['A SQLite backup was opened; restore it by copying the file.']);
        }

        $gz = gzopen($dump, 'rb');
        if ($gz === false) {
            return $this->finish(false, 'read', $source, 0, ['The database dump cannot be read.'], []);
        }

        $created = [];
        $inserts = 0;
        $buffer = '';
        $lastStatement = '';
        while (! gzeof($gz)) {
            $buffer .= (string) gzread($gz, 1 << 20);
            while (($at = strpos($buffer, "\n-- td:statement\n")) !== false) {
                $statement = ltrim(substr($buffer, 0, $at));
                $buffer = substr($buffer, $at + 17);
                $lastStatement = $statement;
                if (preg_match('/^CREATE TABLE `([^`]+)`/m', $statement, $m)) {
                    $created[] = $m[1];
                } elseif (str_starts_with($statement, 'INSERT INTO')) {
                    $inserts++;
                }
            }
        }
        gzclose($gz);

        $problems = [];
        foreach (array_diff($this->tables(null), $created) as $missing) {
            $problems[] = "The live table {$missing} is not in the backup.";
        }
        if (! str_contains($lastStatement.$buffer, 'FOREIGN_KEY_CHECKS=1')) {
            $problems[] = 'The dump does not end where a complete one does: it may be cut short.';
        }

        return $this->finish($problems === [], 'read', $source, count($created), $problems, [
            "{$inserts} batches of rows read.",
            'No scratch database is set (BACKUP_DRILL_DB_*), so the backup was read but not restored.',
        ]);
    }

    /** @return list<string> the tables of this connection's own database (not every database on the server) */
    private function tables(?string $connection): array
    {
        $db = DB::connection($connection);
        $schema = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? $db->getDatabaseName() : null;

        return collect(Schema::connection($connection)->getTableListing($schema, false))
            ->map(fn ($t) => (string) $t)
            ->sort()->values()->all();
    }

    /**
     * @param  list<string>  $tables
     * @return array<string,string> lower-case name => name as listed
     */
    private function byLowerName(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[strtolower($table)] = $table;
        }

        return $out;
    }

    private function empty(string $connection): void
    {
        try {
            Schema::connection($connection)->disableForeignKeyConstraints();
            foreach ($this->tables($connection) as $table) {
                Schema::connection($connection)->drop($table);
            }
            Schema::connection($connection)->enableForeignKeyConstraints();
        } catch (\Throwable) {
            // A scratch database left full is emptied by the next drill's restore.
        }
    }

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $notes
     */
    private function finish(bool $ok, string $mode, string $source, int $tables, array $problems, array $notes): array
    {
        $result = compact('ok', 'mode', 'source', 'tables', 'problems', 'notes');
        Settings::set('backup_drill_last', json_encode(['at' => now()->toIso8601String()] + $result, JSON_UNESCAPED_SLASHES));

        return $result;
    }
}
