<?php

namespace App\Services;

use App\Models\CaseSheetEntry;
use App\Models\DentalNote;
use App\Models\DentalXray;
use App\Models\Hospital;
use App\Models\PatientHistoryEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Support\Practice\VisitForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A dental visit's simple notes (docs/dental.md, "Simple notes"): the
 * presenting complaint, then History and Oral examination as plain text. The
 * choice a dental clinic makes by default in place of the detailed case sheet
 * (App\Support\Practice\VisitForm).
 *
 * Every save is a new version under a lock on the visit, so the record shows
 * what was written and when; a save made against a version that is no longer
 * current is refused rather than overwriting what somebody else wrote. The
 * complaint lives on the visit (`complaints`, with the reason filled from its
 * first words), the Oral examination diagnosis is mirrored into the visit's
 * `diagnosis`, so the lists, the queue, coding and the reports keep reading
 * what they always read. Medical, family and surgical history carry forward:
 * a visit with no notes yet starts from the patient's latest.
 */
class DentalNotesService
{
    /** @var array<string,string> */
    public const GROUPS = [
        'history' => 'History',
        'examination' => 'Oral examination',
    ];

    /** @var array<string, array{label:string, group:string, carried:bool}> */
    public const FIELDS = [
        'medical_history' => ['label' => 'Medical history', 'group' => 'history', 'carried' => true],
        'family_history' => ['label' => 'Family history', 'group' => 'history', 'carried' => true],
        'dental_history' => ['label' => 'Dental history', 'group' => 'history', 'carried' => false],
        'surgical_history' => ['label' => 'Surgical history', 'group' => 'history', 'carried' => true],
        'hard_tissues' => ['label' => 'Hard tissues (teeth)', 'group' => 'examination', 'carried' => false],
        'soft_tissues' => ['label' => 'Soft tissues (gums)', 'group' => 'examination', 'carried' => false],
        'xray' => ['label' => 'X-ray', 'group' => 'examination', 'carried' => false],
        'diagnosis' => ['label' => 'Diagnosis', 'group' => 'examination', 'carried' => false],
    ];

    public const MAX = 2000;

    public function __construct(private readonly VisitService $visits) {}

    /**
     * Whether a visit is written as simple notes. A dental visit is shown the
     * way it was written: notes already saved keep it simple, a case sheet
     * already started keeps it detailed, whatever the hospital chose since.
     * Otherwise the hospital's choice decides.
     */
    public static function appliesTo(Visit $visit, ?Hospital $hospital): bool
    {
        if (! DentalCaseSheetService::appliesTo($visit, $hospital)) {
            return false;
        }
        if (DentalNote::query()->where('visit_id', $visit->id)->exists()) {
            return true;
        }
        // Any part of the detailed case sheet already on the visit: its
        // sections, an X-ray at the chair, a coded diagnosis, or the patient's
        // history asked on it.
        if (CaseSheetEntry::query()->where('visit_id', $visit->id)->exists()
            || DentalXray::query()->where('visit_id', $visit->id)->exists()
            || VisitDiagnosis::query()->where('visit_id', $visit->id)->exists()
            || PatientHistoryEntry::query()->where('visit_id', $visit->id)->exists()) {
            return false;
        }

        return VisitForm::of($hospital)->dentalNotes === VisitForm::SIMPLE;
    }

    /** @return list<string> the fields of one group, in order */
    public static function keys(string $group): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $f) => $f['group'] === $group));
    }

    public function current(Visit $visit): ?DentalNote
    {
        return DentalNote::query()->with('recordedBy:id,name')
            ->where('visit_id', $visit->id)->orderByDesc('version')->first();
    }

    /**
     * The notes in force on a visit; with none saved yet, the patient's
     * latest medical, family and surgical history, named in `carried`.
     *
     * @return array{data:array<string,string>, version:int, recorded_at:?string, recorded_by:?string, carried:list<string>}
     */
    public function forVisit(Visit $visit): array
    {
        $note = $this->current($visit);
        if ($note !== null) {
            return [
                'data' => $this->only($note->data),
                'version' => $note->version,
                'recorded_at' => $note->recorded_at->toIso8601String(),
                'recorded_by' => $note->recordedBy?->name,
                'carried' => [],
            ];
        }

        $carried = $this->carriedForward($visit->patient_id, $visit->id);

        return ['data' => $carried, 'version' => 0, 'recorded_at' => null, 'recorded_by' => null, 'carried' => array_keys($carried)];
    }

    /**
     * The patient's latest saved medical, family and surgical history, from
     * the newest notes on any other visit.
     *
     * @return array<string,string>
     */
    public function carriedForward(int $patientId, ?int $exceptVisitId = null): array
    {
        $latest = DentalNote::query()->where('patient_id', $patientId)
            ->when($exceptVisitId !== null, fn ($q) => $q->where('visit_id', '!=', $exceptVisitId))
            ->orderByDesc('recorded_at')->orderByDesc('id')->first();
        if ($latest === null) {
            return $this->fromCaseSheet($patientId);
        }

        $carried = array_keys(array_filter(self::FIELDS, fn (array $f) => $f['carried']));

        return array_intersect_key($this->only($latest->data), array_flip($carried));
    }

    /**
     * A patient whose history was taken on the detailed case sheet: its
     * medical and surgical history, in words, so moving to simple notes does
     * not ask again what the clinic already knows.
     *
     * @return array<string,string>
     */
    private function fromCaseSheet(int $patientId): array
    {
        $out = [];
        foreach (['medical' => 'medical_history', 'surgical' => 'surgical_history'] as $section => $key) {
            $entry = PatientHistoryEntry::query()->where('patient_id', $patientId)->where('section', $section)
                ->orderByDesc('version')->first();
            $lines = $entry === null ? [] : CaseSheetService::lines($section, (array) $entry->data);
            $text = implode("\n", array_map(fn (array $l) => $l['label'].': '.$l['value'], $lines));
            if ($text !== '') {
                $out[$key] = mb_substr($text, 0, self::MAX);
            }
        }

        return $out;
    }

    /**
     * Save the notes of a visit. `complaints`, when the input has it, is the
     * presenting complaint and goes on the visit. Nothing changed: no new
     * version. Nothing written at all: no notes.
     *
     * @param  array<string,mixed>  $input  the eight notes, and optionally `complaints`
     */
    public function save(Visit $visit, array $input, User $by, ?int $baseVersion = null, ?string $uuid = null, string $errorPrefix = 'notes.'): ?DentalNote
    {
        $data = $this->clean($input, $errorPrefix);
        $withComplaint = array_key_exists('complaints', $input);
        $complaint = $withComplaint ? $this->text($input['complaints'], 'complaints') : null;

        return DB::transaction(function () use ($visit, $data, $by, $baseVersion, $uuid, $withComplaint, $complaint) {
            /** @var Visit $locked */
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($uuid !== null && ($existing = DentalNote::query()->where('uuid', $uuid)->first()) !== null) {
                if ($existing->visit_id !== $locked->id) {
                    throw ValidationException::withMessages(['uuid' => 'That save belongs to another visit.']);
                }

                return $existing; // the same save sent again
            }

            $current = $this->current($locked);
            if ($baseVersion !== null && $baseVersion !== ($current->version ?? 0)) {
                $who = $current->recordedBy->name ?? 'someone else';
                $when = $current?->recorded_at->format('H:i');
                throw ValidationException::withMessages(['notes' => [
                    "{$who} changed these notes".($when ? " at {$when}" : '').' since you opened them. Close them and open them again to see what is there now.',
                ]]);
            }

            $changes = [];
            if ($withComplaint && $complaint !== (string) $locked->complaints) {
                $changes['complaints'] = $complaint === '' ? null : $complaint;
            }

            $was = $current === null ? [] : $this->only($current->data);
            $note = $current;
            if ($data !== $was && ! ($current === null && $data === [])) {
                $note = DentalNote::query()->create([
                    'uuid' => $uuid ?? (string) Str::uuid(),
                    'visit_id' => $locked->id,
                    'patient_id' => $locked->patient_id,
                    'version' => ($current->version ?? 0) + 1,
                    'data' => $data,
                    'recorded_by' => $by->id,
                    'recorded_at' => Carbon::now(),
                ]);

                // The visit's diagnosis follows the notes: what coding, the
                // worklists and the reports read. Emptied here, it is emptied
                // there only if it was the notes' own.
                // A diagnosis written meanwhile somewhere else is kept unless
                // the notes' own diagnosis is what changed.
                $diagnosis = $data['diagnosis'] ?? '';
                $before = $was['diagnosis'] ?? '';
                if ($diagnosis !== $before && $diagnosis !== '' && $diagnosis !== (string) $locked->diagnosis) {
                    $changes['diagnosis'] = $diagnosis;
                } elseif ($diagnosis === '' && $before !== '' && $before === (string) $locked->diagnosis) {
                    $changes['diagnosis'] = null;
                }
            }

            if (($changes !== [] || $note !== $current) && $locked->doctor_user_id === null && $by->can('visits.diagnose')) {
                $changes['doctor_user_id'] = $by->id;
            }
            if ($changes !== []) {
                $this->visits->updateClinical($locked, $changes);
                $visit->setRawAttributes($locked->getAttributes(), true);
            }

            return $note;
        });
    }

    /**
     * The notes as printed: the complaint (the reason on a visit written
     * before there was one), then each group with only the headings written.
     *
     * @return array{complaint:?string, groups:array<string, array{label:string, lines:list<array{label:string, text:string}>}>, recorded_at:?string, recorded_by:?string}
     */
    public function printable(Visit $visit): array
    {
        $note = $this->current($visit);
        $data = $note === null ? [] : $this->only($note->data);

        $groups = [];
        foreach (self::GROUPS as $group => $label) {
            $lines = [];
            foreach (self::keys($group) as $key) {
                if (($data[$key] ?? '') !== '') {
                    $lines[] = ['label' => self::FIELDS[$key]['label'], 'text' => $data[$key]];
                }
            }
            $groups[$group] = ['label' => $label, 'lines' => $lines];
        }

        return [
            'complaint' => filled($visit->complaints) ? (string) $visit->complaints : (filled($visit->reason) ? (string) $visit->reason : null),
            'groups' => $groups,
            'recorded_at' => $note?->recorded_at->toIso8601String(),
            'recorded_by' => $note?->recordedBy?->name,
        ];
    }

    /**
     * Only the eight notes, trimmed and bounded.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    public function clean(array $input, string $errorPrefix = 'notes.'): array
    {
        $out = [];
        $errors = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $value = $input[$key] ?? null;
            $text = is_scalar($value) ? trim((string) $value) : '';
            if (mb_strlen($text) > self::MAX) {
                $errors[$errorPrefix.$key] = ['Keep it under '.self::MAX.' characters.'];
            }
            if ($text !== '') {
                $out[$key] = $text;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function text(mixed $value, string $key): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (mb_strlen($text) > self::MAX) {
            throw ValidationException::withMessages([$key => ['Keep it under '.self::MAX.' characters.']]);
        }

        return $text;
    }

    /**
     * @param  array<mixed>|null  $data
     * @return array<string,string>
     */
    private function only(?array $data): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
