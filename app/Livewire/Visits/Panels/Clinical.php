<?php

namespace App\Livewire\Visits\Panels;

use App\Http\Requests\VisitClinicalRequest;
use App\Livewire\Concerns\ChoosesPhrases;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\VisitPhrases;
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

    private const TEXT_FIELDS = VisitPhrases::NOTE_FIELDS;

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
        return VisitPhrases::context($this->visit);
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
        return VisitPhrases::forNotes($this->visit);
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
