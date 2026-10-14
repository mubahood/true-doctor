<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A standalone installation's database, saved to one file and put back from
 * it (docs/proposals/standalone-edition.md §9).
 *
 * Written in PHP rather than shelling out to mysqldump: buyers' shared hosts
 * do not always have it, and a backup that only works on some servers is the
 * one that will be missing on the day it is needed. MySQL is dumped table by
 * table — structure, rows in batches, and the triggers that keep the
 * installation to one organisation (SingleEnterpriseGuard) — into a gzip file
 * of statements; SQLite is copied as a file.
 *
 * The dump is plain SQL with a fixed separator line between statements, so it
 * can be restored here, by `mysql` on the command line, or by phpMyAdmin.
 */
class DatabaseBackup
{
    private const SEPARATOR = "\n-- td:statement\n";

    private const BATCH = 500;

    /** Write the whole database to `$path` (a .sql.gz for MySQL, .sqlite for SQLite). */
    public function dump(string $path): string
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->dumpMysql($path),
            'sqlite' => $this->dumpSqlite($path),
            default => throw new \RuntimeException('Backups support MySQL and SQLite databases.'),
        };
    }

    /** Put the database back exactly as `$path` holds it. */
    public function restore(string $path): void
    {
        if (! is_file($path)) {
            throw new \RuntimeException('The backup file is missing: '.basename($path));
        }

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $this->restoreMysql($path),
            'sqlite' => $this->restoreSqlite($path),
            default => throw new \RuntimeException('Backups support MySQL and SQLite databases.'),
        };
    }

    private function dumpMysql(string $path): string
    {
        $path = str_ends_with($path, '.sql.gz') ? $path : $path.'.sql.gz';
        $pdo = DB::connection()->getPdo();
        $gz = gzopen($path.'.tmp', 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('The backup file could not be created.');
        }

        try {
            // Each statement ends in a semicolon as well as the separator, so
            // the file also restores with `mysql` or phpMyAdmin.
            $write = fn (string $sql) => gzwrite($gz, rtrim($sql, "; \n").';'.self::SEPARATOR);
            gzwrite($gz, '-- True-Doctor database backup, '.gmdate('c')."\n");
            $write('SET FOREIGN_KEY_CHECKS=0');
            $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
            foreach ($tables as [$table]) {
                $quoted = '`'.str_replace('`', '``', (string) $table).'`';
                $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(\PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS {$quoted}");
                $write((string) $create);

                $offset = 0;
                do {
                    $rows = $pdo->query("SELECT * FROM {$quoted} LIMIT ".self::BATCH." OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
                    if ($rows !== []) {
                        $columns = implode(', ', array_map(fn ($c) => '`'.str_replace('`', '``', $c).'`', array_keys($rows[0])));
                        $values = array_map(fn (array $row) => '('.implode(', ', array_map(
                            fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                            $row,
                        )).')', $rows);
                        $write("INSERT INTO {$quoted} ({$columns}) VALUES ".implode(', ', $values));
                    }
                    $offset += self::BATCH;
                } while (count($rows) === self::BATCH);
            }

            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC) as $trigger) {
                $name = '`'.str_replace('`', '``', (string) $trigger['Trigger']).'`';
                $write("DROP TRIGGER IF EXISTS {$name}");
                $write(sprintf(
                    'CREATE TRIGGER %s %s %s ON `%s` FOR EACH ROW %s',
                    $name, $trigger['Timing'], $trigger['Event'],
                    str_replace('`', '``', (string) $trigger['Table']), $trigger['Statement'],
                ));
            }

            $write('SET FOREIGN_KEY_CHECKS=1');
        } finally {
            gzclose($gz);
        }

        rename($path.'.tmp', $path);
        chmod($path, 0600);

        return $path;
    }

    private function restoreMysql(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('The backup file cannot be read.');
        }

        try {
            // Tables created since the backup must go too, or a restore after a
            // migration leaves the new tables behind.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as [$table]) {
                $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', (string) $table).'`');
            }

            $buffer = '';
            while (! gzeof($gz)) {
                $buffer .= (string) gzread($gz, 1 << 20);
                while (($at = strpos($buffer, self::SEPARATOR)) !== false) {
                    $statement = trim(substr($buffer, 0, $at));
                    $buffer = substr($buffer, $at + strlen(self::SEPARATOR));
                    $this->execute($pdo, $statement);
                }
            }
            $this->execute($pdo, trim($buffer));
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function execute(\PDO $pdo, string $statement): void
    {
        // Drop the leading comment lines, keep the statement.
        $statement = rtrim(trim((string) preg_replace('/^(--[^\n]*\n)+/', '', $statement."\n")), ';');
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    private function dumpSqlite(string $path): string
    {
        $database = (string) DB::connection()->getConfig('database');
        if ($database === ':memory:' || ! is_file($database)) {
            throw new \RuntimeException('An in-memory database cannot be backed up.');
        }

        $path = str_ends_with($path, '.sqlite') ? $path : $path.'.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
        chmod($path, 0600);

        return $path;
    }

    private function restoreSqlite(string $path): void
    {
        $database = (string) DB::connection()->getConfig('database');
        DB::disconnect();
        if (! copy($path, $database)) {
            throw new \RuntimeException('The database file could not be restored.');
        }
        DB::reconnect();
    }
}
