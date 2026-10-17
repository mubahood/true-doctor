<?php

namespace App\Services;

use App\Enums\PatientSex;
use App\Models\CaseSheetEntry;
use App\Models\Patient;
use App\Models\PatientHistoryEntry;
use App\Models\User;
use App\Models\Visit;
use App\Support\CaseSheet\DentalCaseSheet;
use App\Support\Clinical\MedicalFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A case sheet's history and examination, stored as structured, versioned
 * sections (docs/dental.md, "The case sheet").
 *
 * Two kinds of section. A visit section (the presenting complaint, the
 * examination) is written on one visit. A patient section (medical, surgical
 * and financial history) belongs to the patient: it is shown filled from last
 * time and confirmed with "Still correct", or edited. Both are append-only:
 * every save is a new version under a row lock, and a save made against a
 * version that is no longer current is refused rather than overwriting what
 * somebody else wrote meanwhile.
 *
 * The section definitions come from the practice's template
 * (App\Support\CaseSheet\DentalCaseSheet): the same definition draws the web
 * form, checks what the app sends, and prints.
 */
class CaseSheetService
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly PatientService $patients,
    ) {}

    // ── Visit sections ─────────────────────────────────────────────────

    /**
     * The sections in force on a visit: the latest version of each.
     *
     * @return array<string, array{data:array<string,mixed>, version:int, recorded_at:string, recorded_by:?string}>
     */
    public function sections(Visit $visit): array
    {
        $latest = CaseSheetEntry::query()->with('recordedBy:id,name')
            ->where('visit_id', $visit->id)
            ->orderBy('section')->orderByDesc('version')
            ->get()->unique('section');

        $out = [];
        foreach ($latest as $entry) {
            $out[$entry->section] = $this->row($entry->data, $entry->version, $entry->recorded_at, $entry->recordedBy?->name);
        }

        return $out;
    }

    /**
     * Save a visit section. `normal` (for a section that has it) clears the
     * findings that only describe what is not normal.
     *
     * @param  array<string,mixed>  $input
     */
    public function save(Visit $visit, string $section, array $input, User $by, ?int $baseVersion = null, ?string $uuid = null): CaseSheetEntry
    {
        $definition = $this->definition($section, 'visit');
        $data = $this->clean($definition, $input, $visit->patient);

        return DB::transaction(function () use ($visit, $section, $data, $by, $baseVersion, $uuid) {
            Visit::query()->whereKey($visit->id)->lockForUpdate()->first();

            if ($uuid !== null && ($existing = CaseSheetEntry::query()->where('uuid', $uuid)->first()) !== null) {
                return $existing; // the same save sent again
            }

            $current = CaseSheetEntry::query()->with('recordedBy:id,name')
                ->where('visit_id', $visit->id)->where('section', $section)
                ->orderByDesc('version')->first();
            $this->assertCurrent($current->version ?? 0, $baseVersion, $current?->recordedBy?->name, $current?->recorded_at);

            // The first complaint keeps what reception wrote, from whichever
            // screen it comes, rather than mirroring an empty box over it.
            if ($current === null && $section === 'complaint' && ! isset($data['notes']) && filled($visit->complaints)) {
                $data['notes'] = mb_substr((string) $visit->complaints, 0, 2000);
            }

            $entry = CaseSheetEntry::query()->create([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'template' => DentalCaseSheet::TEMPLATE,
                'section' => $section,
                'version' => ($current->version ?? 0) + 1,
                'data' => $data,
                'recorded_by' => $by->id,
                'recorded_at' => Carbon::now(),
            ]);

            $this->afterWriting($visit, $section, $data, $by);

            return $entry;
        });
    }

    /** One tap: the section is normal. What is not a finding (oral hygiene) is kept. */
    public function markNormal(Visit $visit, string $section, User $by, ?int $baseVersion = null): CaseSheetEntry
    {
        $definition = $this->definition($section, 'visit');
        if (empty($definition['normal'])) {
            throw ValidationException::withMessages(['section' => 'This section has no "normal".']);
        }
        $kept = $this->sections($visit)[$section]['data'] ?? [];

        return $this->save($visit, $section, ['normal' => true] + $kept, $by, $baseVersion);
    }

    // ── Patient sections ───────────────────────────────────────────────

    /**
     * The patient's own history: the latest version of each section, with
     * when and on which visit it was last confirmed. The allergies and
     * conditions are read from the patient, where every other screen keeps them.
     *
     * @return array<string, array{data:array<string,mixed>, version:int, recorded_at:?string, recorded_by:?string, confirmed:bool, visit_id:?int}>
     */
    public function patientHistory(Patient $patient): array
    {
        $latest = PatientHistoryEntry::query()->with('recordedBy:id,name')
            ->where('patient_id', $patient->id)
            ->orderBy('section')->orderByDesc('version')
            ->get()->unique('section')->keyBy('section');

        $out = [];
        foreach (DentalCaseSheet::keys('patient') as $section) {
            $entry = $latest->get($section);
            $row = $entry === null
                ? $this->row([], 0, null, null)
                : $this->row($entry->data, $entry->version, $entry->recorded_at, $entry->recordedBy?->name);
            $row['confirmed'] = (bool) $entry?->confirmed;
            $row['visit_id'] = $entry?->visit_id;
            foreach (DentalCaseSheet::section($section)['fields'] ?? [] as $key => $field) {
                if ($field['type'] === 'list') {
                    $value = $this->list($patient->getAttribute($field['attribute']));
                    if ($value === []) {
                        unset($row['data'][$key]);
                    } else {
                        $row['data'][$key] = $value;
                    }
                }
            }
            $out[$section] = $row;
        }

        return $out;
    }

    /** Whether a patient section was confirmed or written on this visit. */
    public function confirmedOn(Patient $patient, string $section, Visit $visit): bool
    {
        return PatientHistoryEntry::query()->where('patient_id', $patient->id)
            ->where('section', $section)->where('visit_id', $visit->id)->exists();
    }

    /**
     * Edit a patient section. Allergies and conditions go on the patient (an
     * empty box leaves them as they were: "None" says there are none); the
     * red flags and pregnancy go on the patient's alert bar.
     *
     * `$seen` is each list as the form showed it: then only what was added
     * and taken out is applied, so an allergy recorded on the patient form
     * meanwhile is kept, not overwritten.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,list<string>|string>|null  $seen
     */
    public function savePatientHistory(Patient $patient, string $section, array $input, ?Visit $visit, User $by, ?int $baseVersion = null, ?string $uuid = null, ?array $seen = null): PatientHistoryEntry
    {
        $definition = $this->definition($section, 'patient');
        $data = $this->clean($definition, $input, $patient);
        $this->assertVisitOf($patient, $visit);

        return DB::transaction(function () use ($patient, $section, $definition, $data, $visit, $by, $baseVersion, $uuid, $seen) {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            if ($uuid !== null && ($existing = PatientHistoryEntry::query()->where('uuid', $uuid)->first()) !== null) {
                return $existing;
            }

            $current = $this->latestPatientEntry($patient, $section);
            $this->assertCurrent($current->version ?? 0, $baseVersion, $current?->recordedBy?->name, $current?->recorded_at);

            // The lists live on the patient; the version keeps what they were.
            $changes = [];
            $patient->refresh();
            foreach ($definition['fields'] as $key => $field) {
                if ($field['type'] !== 'list') {
                    continue;
                }
                $now = $this->list($patient->getAttribute($field['attribute']));
                $sent = $data[$key] ?? [];
                if ($sent === []) {
                    $value = $now; // an empty box changes nothing
                } elseif ($field['attribute'] === 'chronic_conditions' && $this->saysNone($sent)) {
                    $value = []; // "None": no long-term condition
                } elseif ($seen !== null && array_key_exists($key, $seen)) {
                    $was = $this->list($seen[$key]);
                    $value = array_values(array_unique(array_merge(array_diff($now, array_diff($was, $sent)), array_diff($sent, $was))));
                } else {
                    $value = $sent;
                }
                if ($value !== $now) {
                    $changes[$field['attribute']] = $value;
                }
                if ($value === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $value;
                }
            }
            if ($changes !== []) {
                $this->patients->update($patient, $changes);
            }
            if ($section === 'medical') {
                $flags = array_values(array_merge(
                    (array) ($data['red_flags'] ?? []),
                    in_array($data['pregnancy'] ?? null, array_keys(MedicalFlags::PREGNANCY), true) ? [$data['pregnancy']] : [],
                ));
                $patient->forceFill(['medical_flags' => $flags === [] ? null : $flags])->save();
            }

            return PatientHistoryEntry::query()->create([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'patient_id' => $patient->id,
                'visit_id' => $visit?->id,
                'section' => $section,
                'version' => ($current->version ?? 0) + 1,
                'data' => $data,
                'confirmed' => false,
                'recorded_by' => $by->id,
                'recorded_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * "Still correct": asked again on this visit and nothing has changed.
     * A version of its own, so the record shows that somebody asked, and when.
     */
    public function confirmPatientHistory(Patient $patient, string $section, ?Visit $visit, User $by): PatientHistoryEntry
    {
        $this->definition($section, 'patient');
        $this->assertVisitOf($patient, $visit);

        return DB::transaction(function () use ($patient, $section, $visit, $by) {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $current = $this->latestPatientEntry($patient, $section);
            if ($visit !== null && ($asked = PatientHistoryEntry::query()->where('patient_id', $patient->id)
                ->where('section', $section)->where('visit_id', $visit->id)->latest('version')->first()) !== null) {
                return $asked; // already asked on this visit
            }
            $data = $this->patientHistory($patient)[$section]['data'];
            // A pregnancy does not last: it is asked again, never confirmed in one tap.
            if (in_array($data['pregnancy'] ?? null, array_keys(MedicalFlags::PREGNANCY), true)) {
                throw ValidationException::withMessages(['section' => 'The last record says pregnant or breastfeeding. Press Edit and ask again.']);
            }

            return PatientHistoryEntry::query()->create([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'visit_id' => $visit?->id,
                'section' => $section,
                'version' => ($current->version ?? 0) + 1,
                'data' => $data,
                'confirmed' => true,
                'recorded_by' => $by->id,
                'recorded_at' => Carbon::now(),
            ]);
        });
    }

    // ── Reading a section ──────────────────────────────────────────────

    /**
     * A section in words, field by field, leaving out what is empty: the
     * screen's summary, the report's lines and the app's text are the same.
     *
     * @param  array<string,mixed>  $data
     * @return list<array{label:string, value:string}>
     */
    public static function lines(string $section, array $data): array
    {
        $definition = DentalCaseSheet::section($section);
        if ($definition === null) {
            return [];
        }
        $lines = [];
        if (! empty($definition['normal']) && ! empty($data['normal'])) {
            $lines[] = ['label' => 'Findings', 'value' => 'Normal'];
        }
        foreach ($definition['fields'] as $key => $field) {
            $value = $data[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $text = match ($field['type']) {
                'chips' => implode(', ', array_map(fn ($k) => $field['options'][$k] ?? (string) $k, (array) $value)),
                'choice' => $field['options'][$value] ?? (string) $value,
                'list' => implode(', ', (array) $value),
                default => (string) $value,
            };
            $lines[] = ['label' => $field['label'], 'value' => $text];
        }

        return $lines;
    }

    // ── Rules ──────────────────────────────────────────────────────────

    /**
     * What the definition allows, and nothing else: options from the list,
     * text trimmed and bounded, a pregnancy question only for a woman of an
     * age to be asked it.
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function clean(array $definition, array $input, ?Patient $patient): array
    {
        $out = [];
        $errors = [];
        $normal = ! empty($definition['normal']) && filter_var($input['normal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($normal) {
            $out['normal'] = true;
        }

        foreach ($definition['fields'] as $key => $field) {
            if (($normal && ! empty($field['abnormal'])) || (! empty($field['women']) && ! self::asksPregnancy($patient))) {
                continue;
            }
            $value = $input[$key] ?? null;
            switch ($field['type']) {
                case 'chips':
                    $picked = array_map('strval', array_filter((array) $value, fn ($v) => is_scalar($v)));
                    $value = array_values(array_filter(array_keys($field['options']), fn ($k) => in_array((string) $k, $picked, true)));
                    break;
                case 'choice':
                    $value = is_scalar($value) && array_key_exists((string) $value, $field['options']) ? (string) $value : null;
                    break;
                case 'list':
                    $value = $this->list($value);
                    if (count($value) > 30 || collect($value)->contains(fn ($v) => mb_strlen($v) > 100)) {
                        $errors[$key] = ['Keep it to 30 short items.'];
                    }
                    break;
                default:
                    $max = $field['type'] === 'textarea' ? 2000 : 500;
                    $value = is_scalar($value) ? trim((string) $value) : '';
                    if (mb_strlen($value) > $max) {
                        $errors[$key] = ["Keep it under {$max} characters."];
                    }
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$key] = $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** Pregnancy is asked of a woman from 12 to 55, or of one whose age is not known. */
    public static function asksPregnancy(?Patient $patient): bool
    {
        if ($patient === null || $patient->sex !== PatientSex::Female) {
            return false;
        }
        $age = $patient->dob?->age;

        return $age === null || ($age >= 12 && $age <= 55);
    }

    /** @return array<string,mixed> */
    private function definition(string $section, string $scope): array
    {
        $definition = DentalCaseSheet::section($section);
        if ($definition === null || $definition['scope'] !== $scope) {
            throw ValidationException::withMessages(['section' => 'That is not a section of the case sheet.']);
        }

        return $definition;
    }

    /** @param list<string> $items */
    private function saysNone(array $items): bool
    {
        return count($items) === 1 && preg_match('/^(none|nil|no|n\/a)\.?$/i', $items[0]) === 1;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim(is_scalar($v) ? (string) $v : ''), $items), fn ($v) => $v !== '')));
    }

    private function assertCurrent(int $current, ?int $base, ?string $by, ?Carbon $at): void
    {
        if ($base !== null && $base !== $current) {
            $who = $by ?? 'someone else';
            $when = $at?->format('H:i');
            throw ValidationException::withMessages(['section' => [
                "{$who} changed this".($when ? " at {$when}" : '').' since you opened it. Close it and open it again to see what is there now.',
            ]]);
        }
    }

    private function assertVisitOf(Patient $patient, ?Visit $visit): void
    {
        if ($visit !== null && $visit->patient_id !== $patient->id) {
            throw ValidationException::withMessages(['visit' => 'That visit is another patient\'s.']);
        }
    }

    private function latestPatientEntry(Patient $patient, string $section): ?PatientHistoryEntry
    {
        return PatientHistoryEntry::query()->with('recordedBy:id,name')
            ->where('patient_id', $patient->id)->where('section', $section)
            ->orderByDesc('version')->first();
    }

    /**
     * What else a write means: the first clinician to write on a visit with
     * no doctor becomes its doctor, and the complaint is mirrored into the
     * visit's complaints, which the lists, the queue and the older reports read.
     *
     * @param  array<string,mixed>  $data
     */
    private function afterWriting(Visit $visit, string $section, array $data, User $by): void
    {
        $changes = [];
        if ($visit->doctor_user_id === null && $by->can('visits.diagnose')) {
            $changes['doctor_user_id'] = $by->id;
        }
        if ($section === 'complaint') {
            $text = trim(implode('. ', array_filter([
                implode(', ', array_map(fn ($l) => $l['value'], array_filter(self::lines('complaint', $data), fn ($l) => in_array($l['label'], ['Complaint'], true)))),
                $data['where'] ?? null,
                $data['duration'] ?? null,
                $data['notes'] ?? null,
            ])));
            if ($text !== '') {
                $changes['complaints'] = mb_substr($text, 0, 2000);
            }
        }
        if ($changes !== []) {
            $this->visits->updateClinical($visit, $changes);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{data:array<string,mixed>, version:int, recorded_at:?string, recorded_by:?string}
     */
    private function row(array $data, int $version, ?Carbon $at, ?string $by): array
    {
        return ['data' => $data, 'version' => $version, 'recorded_at' => $at?->toIso8601String(), 'recorded_by' => $by];
    }
}
