<?php

namespace App\Services\Reports;

use App\Models\Hospital;
use App\Services\ReminderService;
use Illuminate\Support\Carbon;

/**
 * The days a monthly report covers (docs/reports.md, Monthly reports).
 *
 * A month by default, or any range of whole days. The days are the
 * HOSPITAL's calendar days, as the cashier's day reads them
 * (CashierDaySummary): a payment taken at 23:30 in Kampala belongs to that
 * day, not to the next one in UTC. `start()` and `end()` are those days'
 * edges on the clock the database stores, for timestamp columns; `from` and
 * `to` are the plain dates, for date columns (the books).
 */
final class ReportPeriod
{
    /** The longest custom range one report covers: a year and a day. */
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $zone,
        public readonly ?string $month = null,
    ) {}

    /**
     * A month (`2026-10`), or a range when both dates are given; this month
     * when neither reads. A range back to front is swapped, and one longer
     * than a year is cut to a year from its first day.
     */
    public static function resolve(?string $month, ?string $from, ?string $to, ?Hospital $hospital): self
    {
        $zone = $hospital === null ? (string) config('app.timezone') : app(ReminderService::class)->zone($hospital);

        $f = self::date($from, $zone);
        $t = self::date($to, $zone);
        if ($f !== null && $t !== null) {
            if ($f->gt($t)) {
                [$f, $t] = [$t, $f];
            }
            if ($f->diffInDays($t) >= self::MAX_DAYS) {
                $t = $f->copy()->addDays(self::MAX_DAYS - 1);
            }

            return new self($f->toDateString(), $t->toDateString(), $zone);
        }

        $m = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? Carbon::createFromFormat('Y-m-d', $month.'-01', $zone)->startOfDay()
            : Carbon::now($zone)->startOfMonth();

        return self::ofMonth($m->format('Y-m'), $zone);
    }

    public static function ofMonth(string $month, string $zone): self
    {
        $m = Carbon::createFromFormat('Y-m-d', $month.'-01', $zone)->startOfDay();

        return new self($m->toDateString(), $m->copy()->endOfMonth()->toDateString(), $zone, $m->format('Y-m'));
    }

    public function isMonth(): bool
    {
        return $this->month !== null;
    }

    /** The first moment of the first day, on the database's clock. */
    public function start(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->from, $this->zone)->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    /** The first moment AFTER the last day, on the database's clock (exclusive). */
    public function end(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->to, $this->zone)->startOfDay()->addDay()->setTimezone((string) config('app.timezone'));
    }

    /** The end, or now if the period has not finished: the moment a standing position is read at. */
    public function asAt(): Carbon
    {
        return $this->end()->min(Carbon::now());
    }

    /** The period just before: the month before a month, else the same number of days before. */
    public function previous(): self
    {
        if ($this->month !== null) {
            return self::ofMonth(Carbon::createFromFormat('Y-m-d', $this->from, $this->zone)->subMonthNoOverflow()->format('Y-m'), $this->zone);
        }

        $from = Carbon::parse($this->from);
        $days = (int) $from->diffInDays(Carbon::parse($this->to)) + 1;

        return new self($from->copy()->subDays($days)->toDateString(), $from->copy()->subDay()->toDateString(), $this->zone);
    }

    /** A timestamp as the hospital's own date. */
    public function localDate(mixed $instant): string
    {
        return Carbon::parse($instant, (string) config('app.timezone'))->setTimezone($this->zone)->toDateString();
    }

    /** "October 2026", or "1 Oct 2026 to 15 Oct 2026". */
    public function label(): string
    {
        if ($this->month !== null) {
            return Carbon::parse($this->from)->format('F Y');
        }

        return Carbon::parse($this->from)->format('j M Y').' to '.Carbon::parse($this->to)->format('j M Y');
    }

    /** Every date of the period, in order. @return list<string> */
    public function days(): array
    {
        $out = [];
        for ($d = Carbon::parse($this->from); $d->lte(Carbon::parse($this->to)); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** A short tag for file names: 2026-10, or 2026-10-01_2026-10-15. */
    public function slug(): string
    {
        return $this->month ?? $this->from.'_'.$this->to;
    }

    /** @return array{from:string,to:string,month:?string,label:string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'month' => $this->month, 'label' => $this->label()];
    }

    private static function date(?string $value, string $zone): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $d = Carbon::createFromFormat('Y-m-d', $value, $zone);
        } catch (\Throwable) {
            return null;
        }

        return $d !== null && $d->format('Y-m-d') === $value ? $d->startOfDay() : null;
    }
}
