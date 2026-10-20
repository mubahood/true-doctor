<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The monthly reports a clinic used to keep in spreadsheets (docs/reports.md,
 * Monthly reports): the income book, the expenditure book, the receivables
 * list and the patient register, each as a detailed table and a summary.
 *
 * One entry point for the web page, its exports and the API, so the three
 * can never show different figures for the same month.
 */
class MonthlyReports
{
    /** @var array<string, class-string<MonthlyReport>> */
    public const TYPES = [
        'income' => IncomeReport::class,
        'expenses' => ExpenseReport::class,
        'receivables' => ReceivablesReport::class,
        'patients' => PatientRecordsReport::class,
    ];

    /** @var array<string,string> */
    public const LABELS = [
        'income' => 'Income',
        'expenses' => 'Expenditure',
        'receivables' => 'Accounts receivable',
        'patients' => 'Patient records',
    ];

    /** @var array<string,string> */
    public const VIEWS = ['detail' => 'Detailed table', 'summary' => 'Summary'];

    /** A detailed PDF stops here: past this, the spreadsheet is the document. */
    public const PDF_ROW_LIMIT = 1500;

    /** Seconds a count, a set of totals or a summary is kept for the page and the app. */
    public const TTL = 60;

    public static function type(?string $type): string
    {
        return is_string($type) && isset(self::TYPES[$type]) ? $type : 'income';
    }

    public static function view(?string $view): string
    {
        return is_string($view) && isset(self::VIEWS[$view]) ? $view : 'summary';
    }

    /** The report, for the person signed in (or `$viewer`): what they may read decides the clinical columns. */
    public function report(string $type, ?User $viewer = null): MonthlyReport
    {
        $viewer ??= Auth::user();

        return app(self::TYPES[self::type($type)])->viewer($viewer instanceof User ? $viewer : null);
    }

    /**
     * The hospital the reports are about. Never none: with no hospital in
     * context the tenant scope filters nothing, and a report would add every
     * hospital's figures together (a super admin who has not opened one).
     */
    public static function requireHospital(): void
    {
        abort_if(app(CurrentHospital::class)->id() === null, 404);
    }

    /**
     * A figure kept for a minute per hospital, report, period and reader, so
     * paging through a big month does not count it again on every click.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public function remember(string $what, MonthlyReport $report, ReportPeriod $period, \Closure $work): mixed
    {
        $hospital = app(CurrentHospital::class)->id() ?? 'none';
        $key = 'monthly-reports:'.$hospital.':'.$report::class.':'.$what.':'.$period->from.':'.$period->to.':'.($report->clinical() ? 'c' : 'n');

        return Cache::remember($key, self::TTL, $work);
    }

    /** @return array<string,mixed> */
    public function summary(MonthlyReport $report, ReportPeriod $period): array
    {
        return $this->remember('summary', $report, $period, fn () => $report->summary($period));
    }

    /** @return list<array{label:string,value:string|int,type:string}> */
    public function totals(MonthlyReport $report, ReportPeriod $period): array
    {
        return $this->remember('totals', $report, $period, fn () => $report->totals($period));
    }

    public function count(MonthlyReport $report, ReportPeriod $period): int
    {
        return $this->remember('count', $report, $period, fn () => $report->count($period));
    }

    /**
     * A CSV cell a spreadsheet will not run: text that starts like a formula
     * (= + - @, a tab or a return) gets a quote in front. Numbers are left alone.
     */
    public static function csvSafe(string $cell): string
    {
        if ($cell === '' || is_numeric($cell)) {
            return $cell;
        }

        return in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }

    public function period(?string $month, ?string $from = null, ?string $to = null): ReportPeriod
    {
        return ReportPeriod::resolve($month, $from, $to, app(HospitalSettings::class)->hospital());
    }

    /** A value as a person reads it: the hospital's money format, a written date, a percentage. */
    public static function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return $type === 'percent' ? 'None before' : '';
        }

        return match ($type) {
            'money' => HospitalSettings::money((string) $value),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1 ? Carbon::parse((string) $value)->format('j M Y') : (string) $value,
            'int' => number_format((int) $value),
            'percent' => (bccomp((string) $value, '0', 1) > 0 ? '+' : '').$value.'%',
            default => (string) $value,
        };
    }

    /** A value as a spreadsheet takes it: plain decimals and ISO dates. */
    public static function plain(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return $type === 'percent' ? $value.'%' : (string) $value;
    }

    /**
     * One page of the detailed table: the database reads only the page's
     * rows, and the count is kept for a minute. Its items are rows as
     * MonthlyReport::rows() gives them.
     */
    public function page(string $type, ReportPeriod $period, int $page, int $perPage, ?User $viewer = null): LengthAwarePaginator
    {
        $report = $this->report($type, $viewer);
        $total = $this->count($report, $period);
        $page = max(1, min($page, (int) max(1, ceil($total / max(1, $perPage)))));

        $items = iterator_to_array($report->rows($period, ($page - 1) * $perPage, $perPage), false);

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }
}
