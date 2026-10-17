<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Support\Carbon;

/**
 * What one kind of import knows (docs/imports.md): its columns, how to check a
 * row before anything is saved, how to save it through the service that owns
 * that record, and how to take it back while nothing has happened to it.
 *
 * Values arrive as text keyed by field (what the person's column held,
 * trimmed); the helpers below read dates, amounts, yes/no and lists the way
 * people type them in a spreadsheet.
 */
abstract class ImportHandler
{
    /** @return list<ImportField> */
    abstract public function fields(): array;

    /**
     * Check one row. Nothing is saved.
     *
     * @param  array<string,string>  $v
     * @return array{problems:list<string>,match:array{id:int,reason:string,label:string}|null}
     */
    abstract public function check(array $v): array;

    /**
     * Save one row (already checked) inside the caller's transaction.
     *
     * @param  array<string,string>  $v
     * @param  string  $decision  add | update (a skip never reaches here)
     * @return array{status:string,type:string,id:int,extra?:array<string,mixed>,message?:string}
     */
    abstract public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array;

    /** Why the record a row added cannot be taken back now, or null when it can. */
    abstract public function undoBlocker(ImportRow $row): ?string;

    /** Take back the record a row added (inside the caller's transaction). */
    abstract public function undo(ImportRow $row, ?int $by): void;

    /**
     * Take back what an UPDATE row added beside its changes (a patient's
     * balance brought forward). The changes themselves stay. Returns why it
     * was kept, or null when it was taken back or there was nothing to take.
     */
    public function undoUpdate(ImportRow $row, ?int $by): ?string
    {
        return null;
    }

    /** What can be done with a row that looks like a registered record. @return list<string> */
    public function decisions(): array
    {
        return ['skip', 'update'];
    }

    /**
     * Fields that must have a column, as alternatives: each inner list is
     * satisfied when ANY of its fields is mapped ("Full name", or "First name"
     * and "Last name"). @return list<list<string>>
     */
    public function requiredColumns(): array
    {
        return array_map(fn (ImportField $f) => [$f->key], array_values(array_filter($this->fields(), fn (ImportField $f) => $f->required)));
    }

    /**
     * A key that is the same for two rows of the same sheet describing the
     * same record, to catch a row typed twice. Null: not compared.
     *
     * @param  array<string,string>  $v
     */
    public function sheetKey(array $v): ?string
    {
        return null;
    }

    /** The example row of the template, in field order. @return list<string> */
    public function exampleRow(): array
    {
        return array_map(fn (ImportField $f) => $f->example, $this->fields());
    }

    // ── Reading what people type ──────────────────────────────────────

    /**
     * A date as Y-m-d. Day first, as East Africa writes it (03/04/1990 is
     * 3 April), unless only the other way round makes sense. An Excel day
     * number is read as one. Null when empty, false when unreadable.
     */
    protected function date(string $value): string|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // A year on its own ("1990"): old registers often hold only that.
        // Read as 1 January of it, an estimate like an age.
        if (preg_match('/^(\d{4})$/', $value, $m) === 1 && (int) $m[1] >= 1880 && (int) $m[1] <= 2200) {
            return $m[1].'-01-01';
        }

        // A spreadsheet day number that was not formatted as a date.
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) === 1 && (float) $value > 59 && (float) $value < 80000) {
            return substr(\App\Support\Imports\SpreadsheetReader::serialToDate((float) $value), 0, 10);
        }

        // 2026-03-04 or 2026/03/04, with a time after it or not.
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?!\d)/', $value, $m) === 1) {
            return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 04/03/2026, 4-3-26, 4.3.2026
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4}|\d{2})(?!\d)/', $value, $m) === 1) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += $y > (int) date('y') + 1 ? 1900 : 2000;
            }

            return $b > 12 && $a <= 12 ? $this->ymd($y, $a, $b) : $this->ymd($y, $b, $a);
        }

        // "4 March 2026", "Mar 4, 2026"
        if (preg_match('/[a-z]{3,}/i', $value) === 1) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function ymd(int $y, int $m, int $d): string|false
    {
        return checkdate($m, $d, $y) && $y >= 1880 && $y <= 2200 ? sprintf('%04d-%02d-%02d', $y, $m, $d) : false;
    }

    /** "14:30", "2:30 pm", "2.30PM", "1430", or the time part of a date-time. Null when empty, false when unreadable. */
    protected function time(string $value): string|false|null
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        // A time cell read from Excel comes through as a date-time on 30 December 1899.
        if (preg_match('/\d{4}-\d{2}-\d{2}\s+(\d{1,2}:\d{2})/', $value, $m) === 1) {
            $value = $m[1];
        }
        // A bare fraction of a day (0.5 = 12:00).
        if (preg_match('/^0?\.\d+$/', $value) === 1) {
            $minutes = (int) round((float) $value * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }
        if (preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/', $value, $m) === 1 || preg_match('/^(\d{2})(\d{2})$/', $value, $m) === 1) {
            $h = (int) $m[1];
            $min = (int) ($m[2] ?? 0);
            $half = isset($m[3]) ? str_replace('.', '', $m[3]) : '';
            if ($half === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($half === 'am' && $h === 12) {
                $h = 0;
            }

            return $h < 24 && $min < 60 ? sprintf('%02d:%02d', $h, $min) : false;
        }

        return false;
    }

    /** "25,000", "USh 25,000.50", "25000/=" as "25000.50". Null when empty, false when not an amount. */
    protected function money(string $value): string|false|null
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $clean = (string) preg_replace('/(\/=|\/-)$/', '', $value);
        $clean = (string) preg_replace('/[^\d.\-]/', '', str_replace(',', '', $clean));
        if ($clean === '' || ! is_numeric($clean) || preg_match('/\d/', $value) !== 1) {
            return false;
        }
        if (abs((float) $clean) > 99999999.99) {
            return false;
        }

        return bcadd($clean, '0', 2);
    }

    /** Yes, Y, True, 1, X as true; No, N, False, 0 as false. Null when empty or unclear. */
    protected function yes(string $value): ?bool
    {
        $v = strtolower(trim($value));

        return match (true) {
            in_array($v, ['yes', 'y', 'true', '1', 'x', 'exempt'], true) => true,
            in_array($v, ['no', 'n', 'false', '0'], true) => false,
            default => null,
        };
    }

    /** "Penicillin, Sulfa; peanuts" as a list. @return list<string> */
    protected function list(string $value): array
    {
        $parts = preg_split('/[,;\/\n|]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && ! in_array(strtolower($p), ['none', 'nil', 'n/a', 'na', 'no', '-', 'nkda', 'nka'], true)) {
                $out[] = mb_substr($p, 0, 100);
            }
        }

        return array_values(array_unique($out));
    }

    protected function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
