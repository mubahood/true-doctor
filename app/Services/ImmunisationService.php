<?php

namespace App\Services;

use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use App\Models\Hospital;
use App\Models\Immunisation;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\MotherChild\MotherChild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The immunisation register (roadmap 04 §4): the national schedule from
 * config('mother_child.immunisation'), each child's card (given, due,
 * overdue, upcoming, missed) and the day's list of children with doses due.
 * The rules for the web tab, the list page and the API (docs/mother-child.md).
 *
 * A dose falls due at its age, or later when the previous dose of its series
 * is too recent (the minimum interval). It is "due" for the grace days, then
 * "overdue", and once the child is past the dose's last age it is "missed"
 * and no longer chased. A dose is given once; a wrong entry is voided, never
 * edited, and the dose can then be recorded again.
 */
class ImmunisationService
{
    /** WHO: a dose given up to four days early still counts. */
    public const EARLY_GRACE_DAYS = 4;

    public static function onPatient(Patient $patient, ?Hospital $hospital, ?User $user): bool
    {
        if (! MotherChild::canView($user)) {
            return false;
        }
        if (Immunisation::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }

        return $patient->dob !== null
            && $patient->dob->age < (int) config('mother_child.immunisation_age_years')
            && MotherChild::practises($hospital, ['paediatrics', 'maternity']);
    }

    /**
     * The schedule this child is on (the HPV dose is for girls).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function scheduleFor(?PatientSex $sex): array
    {
        return array_filter(
            config('mother_child.immunisation.schedule'),
            fn (array $d) => ! isset($d['sex']) || ($sex !== null && $d['sex'] === $sex->value),
        );
    }

    /**
     * The child's card on a date: one row per dose, in schedule order.
     *
     * @param  Collection<int, Immunisation>|null  $given  standing doses, when already loaded
     * @return list<array<string,mixed>>
     */
    public function card(Patient $patient, ?Carbon $today = null, ?Collection $given = null): array
    {
        $today ??= Carbon::today();
        $given ??= Immunisation::query()->standing()->where('patient_id', $patient->id)->with('recordedBy:id,name')->get();
        $byKey = $given->keyBy('dose_key');
        if ($patient->dob === null) {
            return [];
        }
        $dob = $patient->dob->copy()->startOfDay();
        $grace = (int) config('mother_child.immunisation.grace_days');
        $defaultMax = (int) config('mother_child.immunisation.default_max_age_days');

        $rows = [];
        $lastInSeries = [];
        foreach (self::scheduleFor($patient->sex) as $key => $dose) {
            $dueOn = $dob->copy()->addDays($dose['age_days']);
            $series = $dose['series'] ?? null;
            if ($series !== null && isset($lastInSeries[$series], $dose['min_interval_days'])) {
                $earliest = $lastInSeries[$series]->copy()->addDays($dose['min_interval_days']);
                $dueOn = $earliest->greaterThan($dueOn) ? $earliest : $dueOn;
            }
            $lastOn = $dob->copy()->addDays($dose['max_age_days'] ?? $defaultMax);

            $record = $byKey->get($key);
            if ($record !== null) {
                $status = 'given';
                if ($series !== null) {
                    $lastInSeries[$series] = $record->given_on->copy();
                }
            } elseif ($today->greaterThan($lastOn)) {
                $status = 'missed';
            } elseif ($today->greaterThan($dueOn->copy()->addDays($grace))) {
                $status = 'overdue';
            } elseif ($today->greaterThanOrEqualTo($dueOn)) {
                $status = 'due';
            } else {
                $status = 'upcoming';
            }

            $rows[] = [
                'key' => $key,
                'vaccine' => $dose['vaccine'],
                'dose' => $dose['dose'],
                'age_label' => $dose['age_days'] === 0 ? 'At birth' : MotherChild::ageLabel($dose['age_days']),
                'due_on' => $dueOn->toDateString(),
                'status' => $status,
                'tone' => self::tone($status),
                'given_on' => $record?->given_on->toDateString(),
                'given_elsewhere' => (bool) $record?->given_elsewhere,
                'batch_no' => $record?->batch_no,
                'uuid' => $record?->uuid,
                'recorded_by' => $record !== null && $record->relationLoaded('recordedBy') ? $record->recordedBy?->name : null,
            ];
        }

        return $rows;
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'given' => 'success',
            'due' => 'info',
            'overdue' => 'danger',
            'missed' => 'warn',
            default => 'neutral',
        };
    }

    /**
     * The card in counts, for the patient summary.
     *
     * @param  list<array<string,mixed>>  $card
     * @return array{given:int,due:int,overdue:int,next:?array<string,mixed>}
     */
    public static function counts(array $card): array
    {
        $by = fn (string $s) => count(array_filter($card, fn ($r) => $r['status'] === $s));
        $next = collect($card)->first(fn ($r) => in_array($r['status'], ['overdue', 'due', 'upcoming'], true));

        return ['given' => $by('given'), 'due' => $by('due'), 'overdue' => $by('overdue'), 'next' => $next === null ? null : [
            'key' => $next['key'], 'vaccine' => $next['vaccine'], 'dose' => $next['dose'], 'due_on' => $next['due_on'], 'status' => $next['status'],
        ]];
    }

    /**
     * Record doses given. Each is checked against the schedule: a dose the
     * child is not on, a date in the future or before birth, a dose given too
     * young or too soon after the previous one, or one already given.
     *
     * @param  list<array<string,mixed>>  $doses  dose_key, given_on, given_elsewhere, batch_no, notes, uuid
     * @return list<Immunisation>
     */
    public function record(Patient $patient, array $doses, ?Visit $visit, User $by): array
    {
        if ($patient->dob === null) {
            throw ValidationException::withMessages(['patient' => 'Record the child\'s date of birth first: the schedule runs from it.']);
        }
        $schedule = self::scheduleFor($patient->sex);
        $dob = $patient->dob->copy()->startOfDay();

        return DB::transaction(function () use ($patient, $doses, $visit, $by, $schedule, $dob) {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $standing = Immunisation::query()->standing()->where('patient_id', $patient->id)->get()->keyBy('dose_key');

            $made = [];
            $errors = [];
            foreach ($doses as $i => $d) {
                if (! empty($d['uuid']) && ($already = Immunisation::query()->where('uuid', $d['uuid'])->where('patient_id', $patient->id)->first())) {
                    $made[] = $already;

                    continue;
                }
                $key = (string) ($d['dose_key'] ?? '');
                $dose = $schedule[$key] ?? null;
                $on = ! empty($d['given_on']) ? Carbon::parse((string) $d['given_on'])->startOfDay() : Carbon::today();

                if ($dose === null) {
                    $errors["doses.{$i}.dose_key"] = 'That dose is not on this child\'s schedule.';

                    continue;
                }
                if ($standing->has($key)) {
                    $errors["doses.{$i}.dose_key"] = "{$dose['vaccine']} {$dose['dose']} is already recorded.";

                    continue;
                }
                if ($on->isFuture() || $on->lessThan($dob)) {
                    $errors["doses.{$i}.given_on"] = 'The date must be between the birth and today.';

                    continue;
                }
                if (MotherChild::days($dob, $on) < $dose['age_days'] - self::EARLY_GRACE_DAYS) {
                    $errors["doses.{$i}.given_on"] = "{$dose['vaccine']} {$dose['dose']} is not given before ".MotherChild::ageLabel($dose['age_days']).' of age.';

                    continue;
                }
                $series = $dose['series'] ?? null;
                if ($series !== null && isset($dose['min_interval_days'])) {
                    $previous = $standing->filter(fn (Immunisation $x) => ($schedule[$x->dose_key]['series'] ?? null) === $series && $x->given_on->lessThanOrEqualTo($on))
                        ->sortByDesc('given_on')->first();
                    if ($previous !== null && MotherChild::days($previous->given_on, $on) < $dose['min_interval_days'] - self::EARLY_GRACE_DAYS) {
                        $errors["doses.{$i}.given_on"] = "Too soon after the previous {$dose['vaccine']} dose: at least {$dose['min_interval_days']} days apart.";

                        continue;
                    }
                }

                $row = Immunisation::create([
                    'uuid' => $d['uuid'] ?? (string) Str::uuid(),
                    'patient_id' => $patient->id,
                    'visit_id' => $visit?->id,
                    'dose_key' => $key,
                    'given_on' => $on->toDateString(),
                    'given_elsewhere' => (bool) ($d['given_elsewhere'] ?? false),
                    'batch_no' => Str::limit(trim((string) ($d['batch_no'] ?? '')), 40, '') ?: null,
                    'notes' => Str::limit(trim((string) ($d['notes'] ?? '')), 500, '') ?: null,
                    'recorded_by' => $by->id,
                ]);
                $standing->put($key, $row);
                $made[] = $row;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $made;
        });
    }

    /** Strike a wrong entry, with the reason. The dose can then be recorded again. */
    public function void(Immunisation $dose, string $reason, User $by): Immunisation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this entry is wrong.']);
        }

        return DB::transaction(function () use ($dose, $reason, $by) {
            $row = Immunisation::query()->whereKey($dose->id)->lockForUpdate()->firstOrFail();
            if ($row->voided_at === null) {
                $row->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => Str::limit($reason, 255, '')])->save();
            }

            return $row;
        });
    }

    /**
     * Children with a dose due or overdue on a date: the day's immunisation
     * list. Children only (under the immunisation age), active, newest
     * overdue first. Bounded by the register's children; their doses are read
     * in one query per chunk.
     *
     * @return Collection<int, array{patient:Patient,due:list<array<string,mixed>>,overdue:int}>
     */
    public function dueList(?Carbon $on = null, string $search = '', string $only = ''): Collection
    {
        $on ??= Carbon::today();
        $out = collect();
        $search = trim($search);

        Patient::query()
            ->where('status', PatientStatus::Active->value)
            ->whereNotNull('dob')
            ->where('dob', '>', $on->copy()->subYears((int) config('mother_child.immunisation_age_years'))->toDateString())
            ->where('dob', '<=', $on->toDateString())
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('patient_no', 'like', "%{$search}%")))
            ->orderBy('id')
            ->chunk(500, function ($children) use ($on, $out, $only) {
                $given = Immunisation::query()->standing()->whereIn('patient_id', $children->pluck('id'))->get()->groupBy('patient_id');
                foreach ($children as $child) {
                    $due = array_values(array_filter(
                        $this->card($child, $on, $given->get($child->id, collect())),
                        fn ($r) => $only === '' ? in_array($r['status'], ['due', 'overdue'], true) : $r['status'] === $only,
                    ));
                    if ($due !== []) {
                        $out->push(['patient' => $child, 'due' => $due, 'overdue' => count(array_filter($due, fn ($r) => $r['status'] === 'overdue'))]);
                    }
                }
            });

        return $out->sortBy([fn ($a, $b) => $b['overdue'] <=> $a['overdue'], fn ($a, $b) => $a['due'][0]['due_on'] <=> $b['due'][0]['due_on']])->values();
    }
}
