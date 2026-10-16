<?php

namespace App\Services;

use App\Enums\AdmissionStatus;
use App\Enums\DoseRecordStatus;
use App\Models\Admission;
use App\Models\DoseItemRecord;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\RadiologyOrder;
use App\Models\Ward;
use App\Support\LabBench;
use App\Support\RadiologyBench;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a ward nurse works from: the doses that are due and the SBAR seed of a
 * handover. Read-only - the writes are NursingChartService / PrescriptionService
 * (a dose is marked through `POST /doses/{record}`) and HandoverService.
 *
 * A prescribed dose has a day and a SLOT, not a clock time, so each slot is
 * given the hour a ward gives it (SLOT_TIMES, in the hospital's time zone). A
 * dose is DUE once that hour has come (or is within `within` minutes) and
 * OVERDUE by the minutes since. Doses older than STALE_DAYS are not chased.
 */
class WardRoundService
{
    /** @var array<string,string> slot => HH:MM, hospital time */
    public const SLOT_TIMES = [
        'morning' => '08:00',
        'afternoon' => '13:00',
        'evening' => '18:00',
        'night' => '22:00',
    ];

    public const STALE_DAYS = 2;

    public function __construct(private readonly ReminderService $zones) {}

    /** The instant a dose is due. */
    public function dueAt(DoseItemRecord $record, Hospital $hospital): Carbon
    {
        $slot = $record->slot->value;
        $tz = $this->zones->zone($hospital);

        return Carbon::createFromFormat('Y-m-d H:i', $record->scheduled_date->toDateString().' '.self::SLOT_TIMES[$slot], $tz)
            ->setTimezone(config('app.timezone'));
    }

    /**
     * Pending doses of admitted patients that are due within `$within` minutes
     * (or already overdue), soonest first.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function dosesDue(Hospital $hospital, ?Ward $ward, int $within): Collection
    {
        $admissions = $this->admitted($ward)->keyBy('visit_id');
        if ($admissions->isEmpty()) {
            return collect();
        }

        $now = now();
        $today = Carbon::now($this->zones->zone($hospital))->startOfDay();

        $records = DoseItemRecord::query()
            ->with('doseItem.prescription')
            ->where('status', DoseRecordStatus::Pending->value)
            ->whereBetween('scheduled_date', [$today->copy()->subDays(self::STALE_DAYS)->toDateString(), $today->copy()->addDay()->toDateString()])
            ->whereHas('doseItem.prescription', fn ($q) => $q->whereIn('visit_id', $admissions->keys()->all()))
            ->get();

        return $records
            ->map(fn (DoseItemRecord $r) => $this->doseRow($r, $admissions[$r->doseItem->prescription->visit_id], $this->dueAt($r, $hospital), $now))
            ->filter(fn ($x) => $x['due']->lte(now()->addMinutes($within)))
            ->sortBy(fn ($x) => $x['due']->getTimestamp())
            ->map(fn ($x) => $x['row'])
            ->values();
    }

    /**
     * @return array{due:Carbon,row:array<string,mixed>}
     */
    private function doseRow(DoseItemRecord $r, Admission $a, Carbon $due, Carbon $now): array
    {
        return [
            'due' => $due,
            'row' => [
                'admission_uuid' => $a->uuid,
                'patient' => $this->patient($a),
                'bed' => $this->bed($a),
                'drug' => $r->doseItem->drug_name,
                'dose' => $r->doseItem->dosage,
                'slot' => $r->slot->value,
                'due_at' => $due->toIso8601String(),
                'overdue_minutes' => max(0, (int) $due->diffInMinutes($now, false)),
                'dose_record_id' => $r->id,
            ],
        ];
    }

    /**
     * One SBAR seed per admitted patient: situation, background, assessment,
     * recommendation, each with a `text` the nurse can edit and the facts it
     * was built from.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function handoverSeed(Hospital $hospital, ?Ward $ward, ?Carbon $since): Collection
    {
        $admissions = $this->admitted($ward);
        $admissions->load('visit:id,uuid,diagnosis,complaints');
        $next = $this->dosesDue($hospital, $ward, 24 * 60)->groupBy('admission_uuid');

        return $admissions->map(fn (Admission $a) => $this->seed($a, $next->get($a->uuid, collect())->take(3)->all(), $since))->values();
    }

    /**
     * @param  list<array<string,mixed>>  $nextDoses
     * @return array<string,mixed>
     */
    private function seed(Admission $a, array $nextDoses, ?Carbon $since): array
    {
        $notes = $a->nursingNotes()->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->limit(5)->pluck('note')->all();

        return [
            'admission_uuid' => $a->uuid,
            'patient' => $this->patient($a),
            'bed' => $this->bed($a),
            'situation' => $this->situation($a),
            'background' => $this->background($a),
            'assessment' => $this->assessment($a),
            'recommendation' => $this->recommendation($a, $nextDoses, $notes),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Admission> */
    private function admitted(?Ward $ward): \Illuminate\Database\Eloquent\Collection
    {
        return Admission::query()
            ->with(['patient:id,uuid,first_name,last_name,patient_no,allergies,chronic_conditions', 'bed.ward'])
            ->where('status', AdmissionStatus::Admitted->value)->whereNotNull('visit_id')
            ->when($ward !== null, fn ($q) => $q->whereHas('bed', fn ($b) => $b->where('ward_id', $ward->id)))
            ->orderBy('admitted_at')->get();
    }

    /** @return array<string,mixed> */
    private function patient(Admission $a): array
    {
        return ['uuid' => $a->patient?->uuid, 'name' => $a->patient?->full_name, 'patient_no' => $a->patient?->patient_no];
    }

    /** @return array<string,mixed> */
    private function bed(Admission $a): array
    {
        return ['name' => $a->bed?->name, 'ward' => $a->bed?->ward?->name, 'ward_id' => $a->bed?->ward_id];
    }

    /** @return array<string,mixed> */
    private function situation(Admission $a): array
    {
        $days = max(1, (int) $a->admitted_at->diffInDays(now()) + 1);
        $diagnosis = $a->visit?->diagnosis;
        $text = trim(($a->reason ?: 'Admitted').($diagnosis ? ". Diagnosis: {$diagnosis}" : '').". Day {$days} of admission.");

        return ['text' => $text, 'reason' => $a->reason, 'diagnosis' => $diagnosis, 'day' => $days];
    }

    /** @return array<string,mixed> */
    private function background(Admission $a): array
    {
        $allergies = array_values((array) $a->patient?->allergies);
        $chronic = array_values((array) $a->patient?->chronic_conditions);
        $history = $a->visit?->complaints;
        $text = implode(' ', array_filter([
            $allergies !== [] ? 'Allergies: '.implode(', ', $allergies).'.' : 'No known allergies recorded.',
            $chronic !== [] ? 'Chronic: '.implode(', ', $chronic).'.' : null,
            $history ? "History: {$history}" : null,
        ]));

        return ['text' => $text, 'allergies' => $allergies, 'chronic' => $chronic, 'history' => $history];
    }

    /**
     * Last vitals, and whether the picture is getting worse. A flag is a
     * reading outside plain adult limits; the trend compares the number of
     * flags in the last two rounds. A nudge to look, not a diagnosis.
     *
     * @return array<string,mixed>
     */
    private function assessment(Admission $a): array
    {
        $rounds = $a->vitalRounds()->limit(2)->get()->values();
        $last = $rounds->get(0);
        if ($last === null) {
            return ['text' => 'No observations recorded yet.', 'last_vitals' => null, 'trend' => 'unknown', 'flags' => []];
        }

        $flags = $this->flags($last);
        $before = $rounds->get(1);
        $trend = $before === null ? 'unknown' : match (true) {
            count($flags) > count($this->flags($before)) => 'worsening',
            count($flags) < count($this->flags($before)) => 'improving',
            default => 'stable',
        };

        $vitals = [
            'temperature' => $last->temperature === null ? null : (string) $last->temperature,
            'pulse' => $last->pulse, 'blood_pressure' => $last->blood_pressure,
            'respiratory_rate' => $last->respiratory_rate, 'spo2' => $last->spo2,
            'at' => $last->created_at?->toIso8601String(),
        ];
        $parts = array_filter([
            $vitals['temperature'] !== null ? "T {$vitals['temperature']}" : null,
            $vitals['pulse'] !== null ? "P {$vitals['pulse']}" : null,
            $vitals['blood_pressure'] ? "BP {$vitals['blood_pressure']}" : null,
            $vitals['respiratory_rate'] !== null ? "RR {$vitals['respiratory_rate']}" : null,
            $vitals['spo2'] !== null ? "SpO2 {$vitals['spo2']}%" : null,
        ]);

        return [
            'text' => 'Last obs '.$last->created_at?->diffForHumans().': '.implode(', ', $parts).($flags ? '. Concern: '.implode(', ', $flags) : '').". Trend {$trend}.",
            'last_vitals' => $vitals, 'trend' => $trend, 'flags' => $flags,
        ];
    }

    /** @return list<string> */
    private function flags(\App\Models\VitalRound $v): array
    {
        $f = [];
        if ($v->temperature !== null && (float) $v->temperature >= 38.0) {
            $f[] = 'fever';
        }
        if ($v->pulse !== null && ($v->pulse > 120 || $v->pulse < 50)) {
            $f[] = 'pulse';
        }
        if ($v->spo2 !== null && $v->spo2 < 92) {
            $f[] = 'low oxygen';
        }
        if ($v->respiratory_rate !== null && ($v->respiratory_rate > 24 || $v->respiratory_rate < 10)) {
            $f[] = 'breathing rate';
        }
        if ($v->blood_pressure && preg_match('/^(\d+)\//', $v->blood_pressure, $m) && ((int) $m[1] < 90 || (int) $m[1] > 180)) {
            $f[] = 'blood pressure';
        }

        return $f;
    }

    /**
     * @param  list<array<string,mixed>>  $nextDoses
     * @param  list<string>  $notes
     * @return array<string,mixed>
     */
    private function recommendation(Admission $a, array $nextDoses, array $notes): array
    {
        $orders = [];
        $labs = LabOrder::query()->where('visit_id', $a->visit_id)->whereIn('status', LabBench::outstandingStatuses())->count();
        $imaging = RadiologyOrder::query()->where('visit_id', $a->visit_id)->whereIn('status', RadiologyBench::outstandingStatuses())->count();
        if ($labs > 0) {
            $orders[] = $labs.' lab '.($labs === 1 ? 'order' : 'orders').' awaiting results';
        }
        if ($imaging > 0) {
            $orders[] = $imaging.' imaging '.($imaging === 1 ? 'order' : 'orders').' awaiting report';
        }

        $meds = array_map(fn ($d) => ['drug' => $d['drug'], 'dose' => $d['dose'], 'slot' => $d['slot'], 'due_at' => $d['due_at']], $nextDoses);
        $text = implode(' ', array_filter([
            $orders !== [] ? 'Pending: '.implode('; ', $orders).'.' : null,
            $meds !== [] ? 'Next meds: '.implode(', ', array_map(fn ($m) => trim("{$m['drug']} {$m['dose']} ({$m['slot']})"), $meds)).'.' : null,
            $notes !== [] ? 'Notes since last handover: '.implode(' | ', $notes) : null,
        ])) ?: 'Continue current plan.';

        return ['text' => $text, 'pending_orders' => $orders, 'meds_due_next' => $meds, 'notes_since_last_handover' => $notes];
    }
}
