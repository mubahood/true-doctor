<?php

namespace App\Services\Hmis;

use App\Enums\AdmissionStatus;
use App\Models\Admission;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Support\CurrentHospital;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Ministry of Health reports (roadmap 04 §2), from the hospital's own
 * records and nothing else:
 *
 *  - HMIS 105, monthly outpatient: attendances (new and re-attendance) and
 *    diagnoses, by the form's age bands and sex;
 *  - HMIS 108, monthly inpatient: admissions, discharges, deaths, bed days
 *    and the diagnoses of those discharged;
 *  - HMIS 033b, weekly surveillance: the notifiable conditions.
 *
 * A visit is counted by its primary coded diagnosis (config/hmis.php maps
 * codes to the form's lines). A visit with no coded diagnosis, no age or no
 * sex is not guessed at: it is listed under "missing data" with a link to fix
 * it, and the count says how many are missing.
 */
class HmisReports
{
    /**
     * The forms, as the page offers them: 105 and 108 for a month, 033b for
     * a week. The page, its files and the API read this one list. (PHP keeps
     * "105" and "108" as integer keys; say `(string) $key` when it matters.)
     *
     * @var array<int|string, array{label:string,period:'month'|'week'}>
     */
    public const FORMS = [
        '105' => ['label' => 'HMIS 105 · outpatients (monthly)', 'period' => 'month'],
        '108' => ['label' => 'HMIS 108 · inpatients (monthly)', 'period' => 'month'],
        '033b' => ['label' => 'HMIS 033b · surveillance (weekly)', 'period' => 'week'],
    ];

    /**
     * The facility the forms are about. Never none: with no hospital in
     * context the tenant scope filters nothing, and a form would add up
     * every hospital's patients (a super admin who has not opened one, or
     * any super admin over the API). As MonthlyReports::requireHospital().
     */
    public static function requireHospital(): void
    {
        abort_if(app(CurrentHospital::class)->id() === null, 404);
    }

    /** The form asked for, or 105 when it is not one of them. */
    public static function form(?string $form): string
    {
        return is_string($form) && isset(self::FORMS[$form]) ? $form : '105';
    }

    /** The month a monthly form opens on: the last whole one, the one being reported. */
    public static function defaultMonth(): string
    {
        return now()->subMonthNoOverflow()->format('Y-m');
    }

    /** The week the weekly form opens on: last week's Monday. */
    public static function defaultWeek(): string
    {
        return now()->subWeek()->startOfWeek()->toDateString();
    }

    /**
     * A form's figures: 105 and 108 for `$month` (`2026-09`), 033b for the
     * week holding `$week` (any day of it). The page, its PDF and CSV and
     * the API read a form through here, so they always count the same days.
     * A month or day that does not read is the default one.
     *
     * @return array<string,mixed>
     */
    public function forForm(string $form, string $month, string $week): array
    {
        $form = self::form($form);
        if ($form === '033b') {
            return $this->weekly($this->day($week) ?? Carbon::parse(self::defaultWeek()));
        }

        // From the first of the month, never "this day of that month": on the
        // 31st, "2026-09" read as Y-m alone would be 1 October.
        $from = $this->day(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month.'-01' : '')
            ?? Carbon::createFromFormat('Y-m-d', self::defaultMonth().'-01')->startOfDay();
        $to = $from->copy()->addMonth();

        return $form === '108' ? $this->ipd($from, $to) : $this->opd($from, $to);
    }

    /**
     * The form's lines as the page draws them, each a grid of age band × sex:
     * the attendances and each diagnosis for 105; admissions, discharges and
     * deaths for 108; none for 033b, which is a list of conditions.
     *
     * @param  array<string,mixed>  $report  what forForm() gave for the form
     * @return array<string, array<string, array<string,int>>> label => band => male/female => count
     */
    public function lines(string $form, array $report): array
    {
        return match (self::form($form)) {
            '033b' => [],
            '108' => ['Admissions' => $report['admissions'], 'Discharges' => $report['discharges'], 'Deaths' => $report['deaths']],
            default => ['New attendance' => $report['attendance']['new'], 'Re-attendance' => $report['attendance']['re']]
                + collect($report['diagnoses'])->mapWithKeys(fn ($d) => [$d['label'] => $d['counts']])->all(),
        };
    }

    /** A real `Y-m-d` date at the start of its day, or null. */
    private function day(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $day instanceof Carbon && $day->format('Y-m-d') === $value ? $day->startOfDay() : null;
    }

    /** @return array<string, array{label:string,codes:list<string>,notifiable?:bool}> */
    public function conditions(): array
    {
        return (array) config('hmis.conditions');
    }

    /** The form line a code is counted under, or null ("all other diagnoses"). */
    public function classify(string $code): ?string
    {
        $code = strtoupper($code);
        foreach ($this->conditions() as $key => $condition) {
            foreach ($condition['codes'] as $prefix) {
                if (str_starts_with($code, strtoupper($prefix))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** The form's age band on a date, or null when the age is not known. */
    public function band(?CarbonInterface $dob, CarbonInterface $on): ?string
    {
        if ($dob === null || $dob->gt($on)) {
            return null;
        }
        $days = (int) $dob->diffInDays($on);
        $years = (int) $dob->diffInYears($on);
        foreach ((array) config('hmis.age_bands') as $key => $band) {
            if (isset($band['to_days']) && $days <= $band['to_days']) {
                return $key;
            }
            if (array_key_exists('to_years', $band) && ! isset($band['to_days']) && ($band['to_years'] === null || $years <= $band['to_years'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * HMIS 105, outpatients, for a month (or any range).
     *
     * @return array{period:array{from:string,to:string}, bands:array<string,string>, attendance:array<string,array<string,array<string,int>>>, diagnoses:array<string,array{label:string,notifiable:bool,counts:array<string,array<string,int>>,total:int}>, other:int, total_visits:int, missing:list<array{visit_uuid:string,visit_no:string,patient:string,patient_uuid:?string,why:list<string>}>}
     */
    public function opd(CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = Visit::query()->with('patient')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', 'cancelled'))
            ->orderBy('id')->get();
        $primary = $this->primaryCodes($visits->pluck('id')->all());
        $firstVisit = $this->firstVisitIds($visits->pluck('patient_id')->unique()->all());

        $attendance = ['new' => $this->grid(), 're' => $this->grid()];
        $diagnoses = [];
        foreach ($this->conditions() as $key => $c) {
            $diagnoses[$key] = ['label' => $c['label'], 'notifiable' => (bool) ($c['notifiable'] ?? false), 'counts' => $this->grid(), 'total' => 0];
        }
        $other = 0;
        $missing = [];

        foreach ($visits as $visit) {
            $patient = $visit->patient;
            $band = $this->band($patient?->dob, $visit->created_at);
            $sex = $this->sex($patient);
            $code = $primary[$visit->id] ?? null;

            $why = array_values(array_filter([
                $band === null ? 'no age' : null,
                $sex === null ? 'no sex' : null,
                $code === null ? 'no coded diagnosis' : null,
            ]));
            if ($why !== []) {
                $missing[] = ['visit_uuid' => (string) $visit->uuid, 'visit_no' => (string) $visit->visit_no, 'patient' => (string) $patient?->full_name, 'patient_uuid' => $patient?->uuid, 'why' => $why];
            }
            if ($band !== null && $sex !== null) {
                $attendance[($firstVisit[$visit->patient_id] ?? null) === $visit->id ? 'new' : 're'][$band][$sex]++;
            }
            if ($code !== null && $band !== null && $sex !== null) {
                $line = $this->classify($code);
                if ($line === null) {
                    $other++;
                } else {
                    $diagnoses[$line]['counts'][$band][$sex]++;
                    $diagnoses[$line]['total']++;
                }
            }
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'bands' => $this->bandLabels(),
            'attendance' => $attendance,
            'diagnoses' => $diagnoses,
            'other' => $other,
            'total_visits' => $visits->count(),
            'missing' => $missing,
        ];
    }

    /**
     * HMIS 108, inpatients, for a month (or any range).
     *
     * @return array{period:array{from:string,to:string}, bands:array<string,string>, admissions:array<string,array<string,int>>, discharges:array<string,array<string,int>>, deaths:array<string,array<string,int>>, absconded:int, bed_days:int, diagnoses:array<string,array{label:string,total:int}>, missing:list<array{visit_uuid:string,visit_no:string,patient:string,patient_uuid:?string,why:list<string>}>}
     */
    public function ipd(CarbonInterface $from, CarbonInterface $to): array
    {
        $admitted = Admission::query()->with(['patient', 'visit'])->where('admitted_at', '>=', $from)->where('admitted_at', '<', $to)->get();
        $left = Admission::query()->with(['patient', 'visit'])->whereNotNull('discharged_at')->where('discharged_at', '>=', $from)->where('discharged_at', '<', $to)->get();
        $overlapping = Admission::query()->where('admitted_at', '<', $to)->where(fn ($q) => $q->whereNull('discharged_at')->orWhere('discharged_at', '>=', $from))->get(['admitted_at', 'discharged_at']);
        $primary = $this->primaryCodes($left->pluck('visit_id')->filter()->all());

        $count = function (Collection $admissions, string $dateColumn) {
            $grid = $this->grid();
            foreach ($admissions as $a) {
                $band = $this->band($a->patient?->dob, $a->{$dateColumn});
                $sex = $this->sex($a->patient);
                if ($band !== null && $sex !== null) {
                    $grid[$band][$sex]++;
                }
            }

            return $grid;
        };

        $bedDays = 0;
        foreach ($overlapping as $a) {
            $start = $a->admitted_at->greaterThan($from) ? $a->admitted_at : Carbon::instance($from);
            $end = $a->discharged_at !== null && $a->discharged_at->lessThan($to) ? $a->discharged_at : Carbon::instance($to);
            $bedDays += max(0, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        }

        $diagnoses = [];
        $missing = [];
        foreach ($left as $a) {
            $code = $a->visit_id !== null ? ($primary[$a->visit_id] ?? null) : null;
            if ($code === null) {
                $missing[] = ['visit_uuid' => (string) $a->visit?->uuid, 'visit_no' => (string) $a->visit?->visit_no, 'patient' => (string) $a->patient?->full_name, 'patient_uuid' => $a->patient?->uuid, 'why' => ['no coded diagnosis']];

                continue;
            }
            $line = $this->classify($code) ?? 'other';
            $diagnoses[$line] ??= ['label' => $line === 'other' ? 'All other diagnoses' : $this->conditions()[$line]['label'], 'total' => 0];
            $diagnoses[$line]['total']++;
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'bands' => $this->bandLabels(),
            'admissions' => $count($admitted, 'admitted_at'),
            'discharges' => $count($left->where('status', AdmissionStatus::Discharged), 'discharged_at'),
            'deaths' => $count($left->where('status', AdmissionStatus::Deceased), 'discharged_at'),
            'absconded' => $left->where('status', AdmissionStatus::Absconded)->count(),
            'bed_days' => $bedDays,
            'diagnoses' => $diagnoses,
            'missing' => $missing,
        ];
    }

    /**
     * HMIS 033b, the week's notifiable conditions (Monday to Sunday): cases
     * from every coded diagnosis in force, deaths from admissions that ended
     * in death that week.
     *
     * @return array{week:array{from:string,to:string,number:int,year:int}, conditions:array<string,array{label:string,cases:int,deaths:int}>}
     */
    public function weekly(CarbonInterface $anyDay): array
    {
        $from = Carbon::instance($anyDay)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $to = $from->copy()->addWeek();

        $rows = [];
        foreach ($this->conditions() as $key => $c) {
            if (! empty($c['notifiable'])) {
                $rows[$key] = ['label' => $c['label'], 'cases' => 0, 'deaths' => 0];
            }
        }

        $visitIds = Visit::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->pluck('id')->all();
        foreach ($this->codesInForce($visitIds) as $codes) {
            foreach (array_unique(array_filter(array_map(fn ($c) => $this->classify($c), $codes))) as $line) {
                if (isset($rows[$line])) {
                    $rows[$line]['cases']++;
                }
            }
        }

        $deaths = Admission::query()->where('status', AdmissionStatus::Deceased)->where('discharged_at', '>=', $from)->where('discharged_at', '<', $to)->pluck('visit_id')->filter()->all();
        foreach ($this->codesInForce($deaths) as $codes) {
            foreach (array_unique(array_filter(array_map(fn ($c) => $this->classify($c), $codes))) as $line) {
                if (isset($rows[$line])) {
                    $rows[$line]['deaths']++;
                }
            }
        }

        return [
            'week' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString(), 'number' => (int) $from->isoWeek(), 'year' => (int) $from->isoWeekYear()],
            'conditions' => $rows,
        ];
    }

    /** Notifiable diagnoses coded since a moment, for the in-charge's alert. @return array<string,int> label => cases */
    public function notifiableSince(CarbonInterface $since): array
    {
        // Visits first coded since then, counted by what is in force now: a
        // diagnosis taken off again is not a case.
        $visitIds = VisitDiagnosis::query()->where('recorded_at', '>=', $since)->whereNull('replaces_id')
            ->distinct()->pluck('visit_id')->map(fn ($id) => (int) $id)->all();
        $out = [];
        foreach ($this->codesInForce($visitIds) as $codes) {
            foreach (array_unique(array_filter(array_map(fn ($c) => $this->classify($c), $codes))) as $line) {
                if (! empty($this->conditions()[$line]['notifiable'])) {
                    $label = $this->conditions()[$line]['label'];
                    $out[$label] = ($out[$label] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /** @param list<int> $visitIds  @return array<int,string> visit id => primary code in force */
    private function primaryCodes(array $visitIds): array
    {
        $out = [];
        foreach ($this->inForce($visitIds) as $d) {
            if ($d->is_primary) {
                $out[$d->visit_id] = $d->code;
            }
        }

        return $out;
    }

    /** @param list<int> $visitIds  @return array<int,list<string>> visit id => codes in force */
    private function codesInForce(array $visitIds): array
    {
        $out = [];
        foreach ($this->inForce($visitIds) as $d) {
            $out[$d->visit_id][] = $d->code;
        }

        return $out;
    }

    /** @param list<int> $visitIds @return Collection<int, VisitDiagnosis> */
    private function inForce(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return new Collection;
        }
        $rows = new Collection;
        foreach (array_chunk($visitIds, 500) as $chunk) {
            $rows = $rows->merge(VisitDiagnosis::query()->whereIn('visit_id', $chunk)->get(['id', 'visit_id', 'code', 'is_primary', 'removed', 'replaces_id']));
        }
        $replaced = $rows->pluck('replaces_id')->filter()->flip();

        return $rows->reject(fn (VisitDiagnosis $d) => $d->removed || $replaced->has($d->id))->values();
    }

    /** @param list<int> $patientIds @return array<int,int> patient id => their first visit's id here */
    private function firstVisitIds(array $patientIds): array
    {
        $out = [];
        foreach (array_chunk($patientIds, 500) as $chunk) {
            foreach (Visit::query()->whereIn('patient_id', $chunk)->selectRaw('patient_id, min(id) as first_id')->groupBy('patient_id')->get() as $r) {
                $out[(int) $r->patient_id] = (int) $r->getAttribute('first_id');
            }
        }

        return $out;
    }

    private function sex(?Patient $patient): ?string
    {
        $sex = $patient?->sex?->value;

        return in_array($sex, ['male', 'female'], true) ? $sex : null;
    }

    /** @return array<string,array<string,int>> band => male/female => 0 */
    private function grid(): array
    {
        return array_map(fn () => ['male' => 0, 'female' => 0], (array) config('hmis.age_bands'));
    }

    /** @return array<string,string> */
    private function bandLabels(): array
    {
        return array_map(fn (array $b) => (string) $b['label'], (array) config('hmis.age_bands'));
    }
}
