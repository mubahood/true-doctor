<?php

namespace App\Livewire\Visits\Panels;

use App\Http\Requests\VisitClinicalRequest;
use App\Livewire\Concerns\ChoosesPhrases;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The doctor's narrative: assigned doctor, complaints, diagnosis, remarks.
 * Writing needs VisitPolicy@diagnose — everyone else who may view the
 * visit sees the notes read-only (the nurse/receptionist view).
 *
 * The dialog carries the things a clinician needs IN FRONT of them while
 * writing, rather than a click away: allergies first, then chronic
 * conditions, what was measured minutes ago, and what this patient was
 * diagnosed with last time. None of it is new data — all of it was already
 * on the record and nobody was showing it at the moment it matters.
 *
 * @property-read array<string, list<array{value:string,label:string,source:string}>> $phrases
 * @property-read array<string, mixed> $context
 */
#[Lazy]
class Clinical extends Component
{
    use AuthorizesRequests, ChoosesPhrases, InteractsWithVisit;

    private const TEXT_FIELDS = ['complaints', 'diagnosis', 'doctor_remarks'];

    /** Long enough for a phrase, short enough that it is not somebody's paragraph. */
    private const PHRASE_MAX = 60;

    /** Enough to recognise one, not so many that reading them is work. */
    private const PHRASE_LIMIT = 8;

    public ?int $doctor_user_id = null;

    public ?string $complaints = null;

    public ?string $diagnosis = null;

    public ?string $doctor_remarks = null;

    /**
     * The panel shows what was written; writing it opens a dialog. Four text
     * areas standing open made the visit page read as a form to fill in.
     */
    public bool $showForm = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        $this->authorize('view', $this->visit);

        $visit = $this->visit;
        $this->doctor_user_id = $visit->doctor_user_id;
        foreach (self::TEXT_FIELDS as $field) {
            $this->{$field} = $visit->{$field};
        }
    }

    /** Bumped on every open, so the doctor picker never keeps a stale pick. */
    public int $formNonce = 0;

    /**
     * What should be in front of whoever is writing this.
     *
     * Allergies lead, because prescribing against one is the mistake this
     * screen is closest to. Everything here is already on the record; the only
     * change is showing it at the moment it is needed.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function context(): array
    {
        $visit = $this->visit;
        $patient = $visit->patient;

        $previous = Visit::where('patient_id', $visit->patient_id)
            ->whereKeyNot($visit->id)
            ->whereNotNull('diagnosis')
            ->where('diagnosis', '!=', '')
            ->latest('id')
            ->first();

        $vitals = array_filter([
            $visit->temperature !== null ? $visit->temperature.'°C' : null,
            $visit->blood_pressure,
            $visit->pulse !== null ? $visit->pulse.' bpm' : null,
            $visit->spo2 !== null ? 'SpO₂ '.$visit->spo2.'%' : null,
            $visit->respiratory_rate !== null ? 'RR '.$visit->respiratory_rate : null,
        ]);

        return [
            'allergies' => $patient === null ? [] : array_values(array_filter((array) $patient->allergies)),
            'conditions' => $patient === null ? [] : array_values(array_filter((array) $patient->chronic_conditions)),
            'reason' => $visit->reason,
            'vitals' => $vitals,
            'previous' => $previous === null ? null : [
                'diagnosis' => (string) $previous->diagnosis,
                'when' => $previous->created_at?->format('d M Y'),
            ],
        ];
    }

    /**
     * Words to reach for, per field.
     *
     * Three sources in order of authority: what reception already wrote on
     * THIS visit, what this hospital writes most often, and a curated set to
     * top it up. Each lands in the box as editable text — it is a typing aid,
     * not a menu to pick a diagnosis from.
     *
     * @return array<string, list<array{value:string,label:string,source:string}>>
     */
    #[Computed]
    public function phrases(): array
    {
        $curated = SampleCatalogue::clinicalPhrases();

        $out = [];
        foreach (self::TEXT_FIELDS as $field) {
            $rows = [];
            $seen = [];

            $add = function (?string $value, string $source) use (&$rows, &$seen) {
                $value = trim((string) $value);
                // Long narratives are not phrases; offering one would paste
                // somebody else's paragraph into this patient's record.
                if ($value === '' || mb_strlen($value) > self::PHRASE_MAX) {
                    return;
                }
                $key = mb_strtolower($value);
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $rows[] = ['value' => $value, 'label' => $value, 'source' => $source];
            };

            // What reception wrote, for the field it answers.
            if ($field === 'complaints') {
                $add($this->visit->reason, 'reception');
            }

            foreach ($this->mostWritten($field) as $value) {
                $add($value, 'hospital');
            }

            foreach ($curated[$field] ?? [] as $value) {
                $add($value, 'curated');
            }

            $out[$field] = array_slice($rows, 0, self::PHRASE_LIMIT);
        }

        return $out;
    }

    /**
     * What this hospital actually writes in that field.
     *
     * More than once, or it is not a house phrase — it is one doctor's
     * sentence about one patient, and offering it to the next would be
     * pasting someone else's note into this record.
     *
     * @return list<string>
     */
    private function mostWritten(string $field): array
    {
        // Fetched wide and trimmed in PHP: the length cap is on CHARACTERS,
        // and the two engines this runs on do not agree on how to count them.
        return Visit::query()
            ->whereNotNull($field)
            ->where($field, '!=', '')
            ->selectRaw($field.', count(*) as n')
            ->groupBy($field)
            ->havingRaw('count(*) > 1')
            ->orderByDesc('n')
            ->limit(self::PHRASE_LIMIT * 4)
            ->pluck($field)
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /** Fill a field from its own phrases, appending rather than replacing. */
    public function usePhrase(string $field, string $value): void
    {
        $this->authorize('diagnose', $this->visit);

        if (! in_array($field, self::TEXT_FIELDS, true)) {
            return;
        }

        $offered = array_column($this->phrases[$field] ?? [], 'value');
        if (! in_array($value, $offered, true)) {
            return;
        }

        // Appended, not replaced — and clicking it again takes it out. The
        // rule lives in ChoosesPhrases so this panel and the new-visit dialog
        // cannot come to disagree about what a second click does.
        $this->togglePhrase($field, $value);
    }

    /** @return list<string> */
    protected function phraseFields(): array
    {
        return self::TEXT_FIELDS;
    }

    /** A <livewire:ui.select-search> child reports its pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name === 'doctor_user_id') {
            $this->doctor_user_id = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'doctor_user_id') {
            $this->doctor_user_id = null;
        }
    }

    public function openForm(): void
    {
        $this->authorize('diagnose', $this->visit);
        $this->resetErrorBag();
        $this->formNonce++;
        unset($this->phrases, $this->context);
        $this->showForm = true;
    }

    public function save(VisitService $service): void
    {
        $visit = $this->visit;
        $this->authorize('diagnose', $visit);

        $this->doctor_user_id = $this->doctor_user_id ?: null;
        foreach (self::TEXT_FIELDS as $field) {
            if (is_string($this->{$field}) && trim($this->{$field}) === '') {
                $this->{$field} = null;
            }
        }

        // Only the keys this form exposes; the rest of the narrative is owned by
        // the intake slide-over on Visits\Index.
        $rules = array_intersect_key(
            VisitClinicalRequest::rulesFor(),
            array_flip(array_merge(['doctor_user_id'], self::TEXT_FIELDS)),
        );

        $service->updateClinical($visit, $this->validate($rules));

        unset($this->visit);

        $this->showForm = false;

        $this->dispatch('toast', message: 'Clinical notes saved.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.clinical');
    }
}
