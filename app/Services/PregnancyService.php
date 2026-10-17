<?php

namespace App\Services;

use App\Enums\PatientSex;
use App\Enums\PregnancyStatus;
use App\Models\AncContact;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\User;
use App\Models\Visit;
use App\Support\MotherChild\MotherChild;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The pregnancy record and its antenatal contacts (roadmap 04 §4): the rules,
 * for the web tab, the API and the app alike (docs/mother-child.md).
 *
 * Dating: the EDD is the LMP plus 280 days (Naegele) unless the clinician
 * gives another (a scan); gestation on any date is read from the EDD, so a
 * corrected EDD corrects everything after it. The contact schedule and the
 * checklist come from config('mother_child.anc'): a contact counts against
 * the first scheduled contact at or after its gestation week. A check is due
 * from the latest contact that asks for it until it is done (a late booking
 * test still counts); one with an `until` contact is missed once that has passed.
 */
class PregnancyService
{
    public const TERM_DAYS = 280;

    /**
     * Whether a patient's record shows the Pregnancy tab to this person: a
     * woman of child-bearing age in a practice that does maternity, or anyone
     * who already has a pregnancy on record.
     */
    public static function onPatient(Patient $patient, ?Hospital $hospital, ?User $user): bool
    {
        if (! MotherChild::canView($user)) {
            return false;
        }
        if (Pregnancy::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }
        if ($patient->sex !== PatientSex::Female || ! MotherChild::practises($hospital, ['maternity'])) {
            return false;
        }
        [$from, $to] = config('mother_child.childbearing_ages');

        // No date of birth: no guess (it could be a girl of six); a record opened another way still shows.
        return $patient->dob !== null && $patient->dob->age >= $from && $patient->dob->age <= $to;
    }

    /** @return array<int,int> contact number => week due */
    public static function contacts(): array
    {
        return config('mother_child.anc.contacts');
    }

    /** @return array<string,string> */
    public static function riskFactors(): array
    {
        return config('mother_child.anc.risk_factors');
    }

    /** @return array<string,array{label:string,at:string|list<int>,until?:int}> */
    public static function checks(): array
    {
        return config('mother_child.anc.checks');
    }

    public static function eddFromLmp(Carbon $lmp): Carbon
    {
        return $lmp->copy()->startOfDay()->addDays(self::TERM_DAYS);
    }

    public static function gestationDays(Pregnancy $pregnancy, ?Carbon $on = null): int
    {
        return self::TERM_DAYS - MotherChild::days($on ?? Carbon::today(), $pregnancy->edd);
    }

    /** "24 w 3 d" */
    public static function gestationLabel(int $days): string
    {
        return $days < 0 ? '—' : intdiv($days, 7).' w '.($days % 7).' d';
    }

    /** The scheduled contact a gestation counts against. */
    public static function contactFor(int $gestationDays): int
    {
        $weeks = intdiv(max(0, $gestationDays), 7);
        foreach (self::contacts() as $no => $week) {
            if ($weeks <= $week) {
                return $no;
            }
        }

        return (int) array_key_last(self::contacts());
    }

    public function current(Patient $patient): ?Pregnancy
    {
        return Pregnancy::query()->where('patient_id', $patient->id)->where('status', PregnancyStatus::Active->value)->latest('id')->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Pregnancy> newest first */
    public function all(Patient $patient): \Illuminate\Database\Eloquent\Collection
    {
        return Pregnancy::query()->where('patient_id', $patient->id)->with(['contacts.recordedBy:id,name'])->latest('edd')->latest('id')->get();
    }

    // ── Writes ───────────────────────────────────────────────────────────

    /**
     * Open a pregnancy. One open at a time, held under a lock on the woman's
     * row; a repeated `uuid` returns the pregnancy already made.
     *
     * @param  array<string,mixed>  $data  lmp, edd, gravida, para, risk_factors, risk_notes, birth plan fields, uuid
     */
    public function open(Patient $patient, array $data, User $by): Pregnancy
    {
        if (! empty($data['uuid']) && ($made = Pregnancy::query()->where('uuid', $data['uuid'])->where('patient_id', $patient->id)->first())) {
            return $made;
        }
        if ($patient->sex !== PatientSex::Female) {
            throw ValidationException::withMessages(['patient' => 'A pregnancy can only be recorded for a female patient.']);
        }

        $fields = $this->validated($data, null);

        return DB::transaction(function () use ($patient, $fields, $data, $by) {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();
            if ($this->current($patient) !== null) {
                throw ValidationException::withMessages(['patient' => 'She already has a current pregnancy. Close it before opening another.']);
            }

            return Pregnancy::create($fields + [
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'patient_id' => $patient->id,
                'status' => PregnancyStatus::Active->value,
                'recorded_by' => $by->id,
            ]);
        });
    }

    /**
     * Change the dating, history, risks or birth plan of a current pregnancy.
     * Partial: a key not sent keeps its value.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(Pregnancy $pregnancy, array $data, User $by): Pregnancy
    {
        $this->mustBeOpen($pregnancy);
        $fields = $this->validated($data, $pregnancy);

        return DB::transaction(function () use ($pregnancy, $fields) {
            $row = Pregnancy::query()->whereKey($pregnancy->id)->lockForUpdate()->firstOrFail();
            $row->fill($fields)->save();

            return $row;
        });
    }

    /**
     * Record an antenatal contact. Append-only; a repeated `uuid` is recorded once.
     *
     * @param  array<string,mixed>  $data  seen_on, blood_pressure, weight, fundal_height, fetal_heart_rate, checks, notes, uuid
     */
    public function recordContact(Pregnancy $pregnancy, array $data, ?Visit $visit, User $by): AncContact
    {
        if (! empty($data['uuid']) && ($made = AncContact::query()->where('uuid', $data['uuid'])->where('pregnancy_id', $pregnancy->id)->first())) {
            return $made;
        }
        $this->mustBeOpen($pregnancy);

        $seen = isset($data['seen_on']) && $data['seen_on'] !== '' ? Carbon::parse((string) $data['seen_on'])->startOfDay() : Carbon::today();
        $errors = [];
        $gestation = self::gestationDays($pregnancy, $seen);
        if ($seen->isFuture()) {
            $errors['seen_on'] = 'The contact cannot be in the future.';
        } elseif ($gestation < 0) {
            $errors['seen_on'] = 'That date is before this pregnancy began.';
        } elseif ($gestation > 45 * 7) {
            $errors['seen_on'] = 'That date is past 45 weeks: check the EDD.';
        }

        $bp = trim((string) ($data['blood_pressure'] ?? ''));
        if ($bp !== '' && ! preg_match('#^(\d{2,3})\s*/\s*(\d{2,3})$#', $bp)) {
            $errors['blood_pressure'] = 'Write the blood pressure as 120/80.';
        }
        $weight = $this->number($data['weight'] ?? null, 25, 250, 'weight', 'Weight must be between 25 and 250 kg.', $errors);
        $fundal = $this->number($data['fundal_height'] ?? null, 5, 50, 'fundal_height', 'Fundal height must be between 5 and 50 cm.', $errors);
        $fhr = $this->number($data['fetal_heart_rate'] ?? null, 60, 220, 'fetal_heart_rate', 'Fetal heart rate must be between 60 and 220.', $errors);
        $checks = array_values(array_unique(array_map('strval', (array) ($data['checks'] ?? []))));
        if (array_diff($checks, array_keys(self::checks())) !== []) {
            $errors['checks'] = 'Unknown item in the checklist.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return AncContact::create([
            'uuid' => $data['uuid'] ?? (string) Str::uuid(),
            'pregnancy_id' => $pregnancy->id,
            'patient_id' => $pregnancy->patient_id,
            'visit_id' => $visit?->id,
            'seen_on' => $seen->toDateString(),
            'gestation_days' => $gestation,
            'contact_no' => self::contactFor($gestation),
            'blood_pressure' => $bp === '' ? null : preg_replace('#\s+#', '', $bp),
            'weight' => $weight === null ? null : number_format($weight, 2, '.', ''),
            'fundal_height' => $fundal === null ? null : (int) $fundal,
            'fetal_heart_rate' => $fhr === null ? null : (int) $fhr,
            'checks' => $checks,
            'notes' => Str::limit(trim((string) ($data['notes'] ?? '')), 500, '') ?: null,
            'recorded_by' => $by->id,
        ]);
    }

    /** End a pregnancy: delivered, or ended otherwise. Final. */
    public function close(Pregnancy $pregnancy, PregnancyStatus $status, ?Carbon $on, ?string $notes, User $by): Pregnancy
    {
        if ($status === PregnancyStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Choose how the pregnancy ended.']);
        }
        $on ??= Carbon::today();
        if ($on->isFuture()) {
            throw ValidationException::withMessages(['ended_on' => 'The date cannot be in the future.']);
        }

        return DB::transaction(function () use ($pregnancy, $status, $on, $notes) {
            $row = Pregnancy::query()->whereKey($pregnancy->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($row);
            if (self::gestationDays($row, $on) < 0) {
                throw ValidationException::withMessages(['ended_on' => 'That date is before this pregnancy began.']);
            }
            $row->fill(['status' => $status->value, 'ended_on' => $on->toDateString(), 'outcome_notes' => Str::limit(trim((string) $notes), 500, '') ?: null])->save();

            return $row;
        });
    }

    // ── Reads ────────────────────────────────────────────────────────────

    /**
     * Where a pregnancy stands on a date (today, or the day it ended): the
     * gestation, each scheduled contact (done, missed, due, upcoming), the
     * checks due at this contact and those missed, and the risks worth seeing.
     *
     * @return array<string,mixed>
     */
    public function schedule(Pregnancy $pregnancy, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        if ($pregnancy->status !== PregnancyStatus::Active && $pregnancy->ended_on !== null) {
            $today = $pregnancy->ended_on->copy();
        }
        $contacts = $pregnancy->relationLoaded('contacts') ? $pregnancy->contacts : $pregnancy->contacts()->get();
        $gestation = self::gestationDays($pregnancy, $today);
        $now = self::contactFor($gestation);
        $start = $pregnancy->edd->copy()->subDays(self::TERM_DAYS);

        $byNo = $contacts->groupBy('contact_no');
        $schedule = [];
        $next = null;
        foreach (self::contacts() as $no => $week) {
            $done = $byNo->get($no);
            $status = $done !== null ? 'done' : ($no < $now ? 'missed' : ($no === $now ? 'due' : 'upcoming'));
            $row = [
                'no' => $no, 'week' => $week, 'due_on' => $start->copy()->addWeeks($week)->toDateString(),
                'status' => $status, 'seen_on' => $done?->first()?->seen_on->toDateString(),
            ];
            $schedule[] = $row;
            if ($next === null && in_array($status, ['due', 'upcoming'], true)) {
                $next = $row;
            }
        }

        $due = [];
        $missed = [];
        foreach (self::checks() as $key => $check) {
            // The latest contact that asks for this check, reached by now.
            $at = $check['at'] === 'all' ? array_keys(self::contacts()) : $check['at'];
            $reached = array_filter($at, fn (int $slot) => $slot <= $now);
            if ($reached === []) {
                continue;
            }
            $slot = max($reached);
            // Done at that contact or since (a booking test done late still counts).
            if ($contacts->contains(fn (AncContact $c) => $c->contact_no >= $slot && in_array($key, (array) $c->checks, true))) {
                continue;
            }
            // Outstanding: still due, unless its time has passed (a dating scan).
            if (isset($check['until']) && $now > $check['until']) {
                $missed[] = ['key' => $key, 'label' => $check['label'], 'contact' => $slot];
            } else {
                $due[] = ['key' => $key, 'label' => $check['label']];
            }
        }

        return [
            'gestation_days' => $gestation,
            'gestation_label' => self::gestationLabel($gestation),
            'trimester' => $gestation < 14 * 7 ? 1 : ($gestation < 28 * 7 ? 2 : 3),
            'contact_now' => $now,
            'contacts' => $schedule,
            'next' => $next,
            'due_checks' => $due,
            'missed_checks' => $missed,
            'missed_contacts' => count(array_filter($schedule, fn ($c) => $c['status'] === 'missed')),
            'flags' => $this->flags($pregnancy, $contacts, $gestation),
        ];
    }

    /**
     * The risks worth putting in front of the clinician: those recorded, and
     * those the record itself shows (her age, five births, a raised blood
     * pressure at the last contact, past her date).
     *
     * @param  \Illuminate\Support\Collection<int, AncContact>  $contacts
     * @return list<array{key:string,label:string,tone:string}>
     */
    public function flags(Pregnancy $pregnancy, \Illuminate\Support\Collection $contacts, int $gestation): array
    {
        $labels = self::riskFactors();
        $keys = (array) $pregnancy->risk_factors;

        $dob = $pregnancy->patient->dob;
        $age = $dob === null ? null : (int) floor($dob->diffInYears($pregnancy->edd->copy()->subDays(self::TERM_DAYS)));
        if ($age !== null && $age < 18) {
            $keys[] = 'teen';
        }
        if ($age !== null && $age > 35) {
            $keys[] = 'older';
        }
        if ($pregnancy->para >= 5) {
            $keys[] = 'grand_multipara';
        }
        $flags = array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k] ?? $k, 'tone' => 'warn'], array_values(array_unique($keys)));

        $last = $contacts->filter(fn (AncContact $c) => $c->blood_pressure !== null)->last();
        if ($last && preg_match('#^(\d+)/(\d+)$#', (string) $last->blood_pressure, $m) && ((int) $m[1] >= 140 || (int) $m[2] >= 90)) {
            $flags[] = ['key' => 'raised_bp', 'label' => 'Blood pressure '.$last->blood_pressure.' at the last contact', 'tone' => 'danger'];
        }
        if ($pregnancy->status === PregnancyStatus::Active && $gestation > 41 * 7) {
            $flags[] = ['key' => 'post_dates', 'label' => 'Past 41 weeks', 'tone' => 'danger'];
        }

        return $flags;
    }

    /**
     * The current pregnancy in a line, for the patient summary (web and app).
     *
     * @return array<string,mixed>|null
     */
    public function brief(Patient $patient): ?array
    {
        $p = $this->current($patient);
        if ($p === null) {
            return null;
        }
        $s = $this->schedule($p->setRelation('patient', $patient));

        return [
            'uuid' => $p->uuid, 'edd' => $p->edd->toDateString(), 'gestation_label' => $s['gestation_label'],
            'gravida' => $p->gravida, 'para' => $p->para,
            'next_contact' => $s['next'], 'missed_contacts' => $s['missed_contacts'],
            'flags' => array_column($s['flags'], 'label'),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function validated(array $data, ?Pregnancy $current): array
    {
        $errors = [];
        $fields = [];
        $has = fn (string $k) => array_key_exists($k, $data);

        $lmp = $has('lmp') ? ($data['lmp'] ? Carbon::parse((string) $data['lmp'])->startOfDay() : null) : $current?->lmp;
        $edd = $has('edd') && $data['edd'] ? Carbon::parse((string) $data['edd'])->startOfDay() : null;
        if ($lmp !== null && ($lmp->isFuture() || MotherChild::days($lmp, Carbon::today()) > 44 * 7)) {
            $errors['lmp'] = 'The last period must be within the last 44 weeks.';
        }
        if ($edd === null && $lmp !== null && ($has('lmp') || $current === null)) {
            $edd = self::eddFromLmp($lmp);
        }
        if ($edd === null && $current === null) {
            $errors['edd'] = 'Give the last menstrual period or the expected date of delivery.';
        }
        if ($edd !== null) {
            $toEdd = MotherChild::days(Carbon::today(), $edd);
            if ($toEdd < -30 || $toEdd > self::TERM_DAYS + 14) {
                $errors['edd'] = 'The expected date of delivery must be within the next 42 weeks.';
            }
            $fields['edd'] = $edd->toDateString();
            $fields['edd_from_lmp'] = $lmp !== null && $edd->equalTo(self::eddFromLmp($lmp));
        }
        if ($has('lmp')) {
            $fields['lmp'] = $lmp?->toDateString();
        }

        $gravida = $has('gravida') ? $data['gravida'] : $current?->gravida;
        $para = $has('para') ? ($data['para'] ?? 0) : ($current->para ?? 0);
        if (! is_numeric($gravida) || (int) $gravida < 1 || (int) $gravida > 30) {
            $errors['gravida'] = 'Gravida (pregnancies, this one included) must be 1 or more.';
        } elseif (! is_numeric($para) || (int) $para < 0 || (int) $para >= (int) $gravida) {
            $errors['para'] = 'Para (births before this pregnancy) must be less than gravida.';
        } else {
            $fields['gravida'] = (int) $gravida;
            $fields['para'] = (int) $para;
        }

        if ($has('risk_factors')) {
            $risks = array_values(array_unique(array_map('strval', (array) $data['risk_factors'])));
            if (array_diff($risks, array_keys(self::riskFactors())) !== []) {
                $errors['risk_factors'] = 'Unknown risk factor.';
            }
            $fields['risk_factors'] = $risks;
        }
        foreach (['risk_notes' => 500, 'birth_place' => 120, 'birth_companion' => 120, 'transport' => 120, 'blood_donor' => 120, 'birth_plan_notes' => 500] as $key => $max) {
            if ($has($key)) {
                $value = trim((string) $data[$key]);
                if (mb_strlen($value) > $max) {
                    $errors[$key] = "At most {$max} characters.";
                }
                $fields[$key] = $value === '' ? null : $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return Arr::only($fields, ['lmp', 'edd', 'edd_from_lmp', 'gravida', 'para', 'risk_factors', 'risk_notes', 'birth_place', 'birth_companion', 'transport', 'blood_donor', 'birth_plan_notes']);
    }

    private function mustBeOpen(Pregnancy $pregnancy): void
    {
        if ($pregnancy->status !== PregnancyStatus::Active) {
            throw ValidationException::withMessages(['pregnancy' => 'This pregnancy is closed.']);
        }
    }

    /** @param array<string,string> $errors */
    private function number(mixed $value, float $min, float $max, string $key, string $message, array &$errors): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            $errors[$key] = $message;

            return null;
        }

        return (float) $value;
    }
}
