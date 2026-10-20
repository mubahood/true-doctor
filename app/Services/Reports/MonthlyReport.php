<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Practice\Practice;

/**
 * One of the monthly reports (docs/reports.md, Monthly reports): the rows of
 * its detailed table, the totals under them, and its summary.
 *
 * Everything is read from the records the rest of the system keeps; nothing
 * is stored. Money stays a decimal string from the database to the page
 * (bcmath), and the summary's figures are database sums wherever the
 * database can give them, so a big month costs a handful of queries.
 *
 * A column's `type` says how to show it: text, date (Y-m-d), money (a
 * decimal string), int, or percent.
 */
abstract class MonthlyReport
{
    /** What the report is called on the page and on paper. */
    abstract public function title(): string;

    /** @return list<array{key:string,label:string,type:string}> */
    abstract public function columns(): array;

    /** Whether the person reading may see clinical notes (`visits.clinical`). */
    protected bool $clinical = true;

    /** Who is reading: clinical columns are left blank for somebody who may not read the notes. */
    public function viewer(?User $user): static
    {
        $this->clinical = (bool) $user?->can('visits.clinical');

        return $this;
    }

    public function clinical(): bool
    {
        return $this->clinical;
    }

    /**
     * The rows of the detailed table, in order, from `$offset`, at most
     * `$limit` of them (all when null). Read in chunks, so a large month
     * never sits in memory at once, and a page reads only its own rows.
     *
     * @return \Generator<int, array<string, string|int|null>>
     */
    abstract public function rows(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator;

    /** How many rows the detailed table has. */
    abstract public function count(ReportPeriod $period): int;

    /**
     * The lines under the detailed table.
     *
     * @return list<array{label:string,value:string|int,type:string}>
     */
    abstract public function totals(ReportPeriod $period): array;

    /**
     * Headline figures and the summary's tables.
     *
     * @return array{figures:list<array{label:string,value:string|int|null,type:string,note?:string}>,tables:list<array{key:string,title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,string|int|null>>,total?:array<string,string|int|null>|null,note?:string}>}
     */
    abstract public function summary(ReportPeriod $period): array;

    /** What the practice calls the clinician who saw the patient. */
    public static function clinician(): string
    {
        return Practice::current()->sector->key === 'dental_clinic' ? 'Dentist' : 'Doctor';
    }

    /**
     * A database sum as a two-place decimal string. MySQL returns DECIMAL
     * sums exactly as strings; SQLite (the tests) returns a float, which is
     * rounded to the cent once here rather than truncated.
     */
    protected static function dec(mixed $value): string
    {
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        return is_numeric($value) ? bcadd((string) $value, '0', 2) : '0.00';
    }

    /** @param list<string> $amounts */
    protected static function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $c, string $a) => bcadd($c, $a, 2), '0.00');
    }

    /** Change from `then` to `now` as a percentage to one place, or null when there was nothing before. */
    protected static function change(string $now, string $then): ?string
    {
        if (bccomp($then, '0', 2) === 0) {
            return null;
        }

        return bcdiv(bcmul(bcsub($now, $then, 2), '100', 4), $then, 1);
    }

    /**
     * An ordered query read a chunk at a time, from an offset, up to a limit.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>|\Illuminate\Database\Query\Builder  $query  ordered on something unique
     * @return \Generator<int, \Illuminate\Support\Collection<int, mixed>> one chunk at a time
     */
    protected static function window($query, int $offset, ?int $limit, int $chunk = 500): \Generator
    {
        $given = 0;
        while ($limit === null || $given < $limit) {
            $take = $limit === null ? $chunk : min($chunk, $limit - $given);
            $batch = $query->clone()->offset($offset + $given)->limit($take)->get();
            if ($batch->isNotEmpty()) {
                yield $batch;
            }
            $given += $batch->count();
            if ($batch->count() < $take) {
                return;
            }
        }
    }

    /**
     * The comparison table every money report ends with: each figure this
     * period, the period before, and the difference.
     *
     * @param  array<string, array{0:string,1:string}>  $lines  label => [now, then]
     * @return array{key:string,title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,string|int|null>>}
     */
    protected static function comparison(array $lines, ReportPeriod $period): array
    {
        $rows = [];
        foreach ($lines as $label => [$now, $then]) {
            $rows[] = [
                'figure' => $label,
                'this' => $now,
                'previous' => $then,
                'difference' => bcsub($now, $then, 2),
                'change' => self::change($now, $then),
            ];
        }

        return [
            'key' => 'comparison',
            'title' => 'Compared with '.$period->previous()->label(),
            'columns' => [
                ['key' => 'figure', 'label' => 'Figure', 'type' => 'text'],
                ['key' => 'this', 'label' => $period->label(), 'type' => 'money'],
                ['key' => 'previous', 'label' => $period->previous()->label(), 'type' => 'money'],
                ['key' => 'difference', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'change', 'label' => 'Change', 'type' => 'percent'],
            ],
            'rows' => $rows,
        ];
    }
}
