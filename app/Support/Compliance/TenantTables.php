<?php

namespace App\Support\Compliance;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The tables a hospital's (or a patient's) data lives in, read straight from
 * the database catalogue, and what must never leave in an export.
 *
 * Found rather than listed, like `hospitals:purge` finds them: a table added
 * next month is in the next export without anybody remembering to add it.
 * These are raw table reads, so they filter on `hospital_id` themselves; the
 * models' global scope does not apply to DB::table(). The feature tests prove
 * another hospital's rows never appear.
 */
class TenantTables
{
    /**
     * Operational tables that are not the hospital's records: the offline
     * sync machinery, gateway payloads, number counters and the exports
     * themselves.
     */
    public const SKIP = [
        'sync_operations', 'sync_revisions', 'sync_sessions', 'sync_conflicts', 'sync_tombstones',
        'gateway_logs', 'sequences', 'message_credits', 'data_exports', 'devices',
    ];

    /** Columns that never leave: secrets, password hashes, lookup hashes and link nonces. */
    public const OMIT = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step',
        'card_hash', 'card_number_hash', 'share_nonce', 'pull_cursor',
        // A patient link's secret and code, hashed: no use to anyone outside, a risk if leaked.
        'token_hash', 'code_hash',
    ];

    /** Columns stored encrypted at rest; an export carries them readable. */
    public const ENCRYPTED = ['patients.bank_details', 'patient_cards.card_number'];

    /**
     * Uploaded files, by where their path is written: table, column, disk.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const FILES = [
        ['patient_documents', 'file_path', 'local'],
        ['attachments', 'file_path', 'local'],
        ['treatment_record_items', 'photo_path', 'local'],
        ['patients', 'photo', 'public'],
        ['users', 'avatar', 'public'],
    ];

    /** @var array<string, list<array{name:string,type:string,nullable:bool}>> */
    private array $columns = [];

    /** @var list<string>|null */
    private ?array $all = null;

    /**
     * Every base table with this column, in name order, without SKIP.
     *
     * @return list<string>
     */
    public function withColumn(string $column): array
    {
        $tables = array_values(array_filter($this->all(), fn (string $t) => ! in_array($t, self::SKIP, true)
            && in_array($column, array_column($this->columns($t), 'name'), true)));
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    private function all(): array
    {
        // MySQL lists every schema the account can see unless told which one.
        $schema = DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;

        return $this->all ??= array_values(array_map(fn ($t) => (string) $t['name'], Schema::getTables($schema)));
    }

    /**
     * The columns of a table, for the export's column list.
     *
     * @return list<array{name:string,type:string,nullable:bool}>
     */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_values(array_map(fn (array $c) => [
            'name' => (string) $c['name'],
            'type' => (string) ($c['type_name'] ?? $c['type'] ?? ''),
            'nullable' => (bool) ($c['nullable'] ?? true),
        ], Schema::getColumns($table)));
    }

    /** @return list<string> the columns of a table that may leave */
    public function exportable(string $table): array
    {
        return array_values(array_filter(
            array_column($this->columns($table), 'name'),
            fn (string $c) => ! in_array($c, self::OMIT, true),
        ));
    }

    public function has(string $table, string $column): bool
    {
        return in_array($column, array_column($this->columns($table), 'name'), true);
    }

    /**
     * One row as it leaves: secret columns gone, encrypted ones readable.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public function clean(string $table, array $row): array
    {
        foreach (self::OMIT as $column) {
            unset($row[$column]);
        }

        foreach (self::ENCRYPTED as $qualified) {
            [$t, $column] = explode('.', $qualified);
            if ($t === $table && isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $row[$column] = $this->decrypt($row[$column]);
            }
        }

        return $row;
    }

    /**
     * The hospital's own settings without anything that looks like a key.
     *
     * @param  array<int|string,mixed>  $settings
     * @return array<int|string,mixed>
     */
    public static function withoutSecrets(array $settings): array
    {
        $out = [];
        foreach ($settings as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), ['secret', 'password', 'token', 'api_key', 'apikey', 'private'])) {
                continue;
            }
            $out[$key] = is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $out;
    }

    private function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Saved before the column was encrypted, or under another key:
            // say so rather than hand over ciphertext as if it were the value.
            return '[could not be decrypted]';
        }
    }
}
