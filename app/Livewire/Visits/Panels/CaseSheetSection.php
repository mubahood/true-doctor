<?php

namespace App\Livewire\Visits\Panels;

use App\Models\Patient;
use App\Services\CaseSheetService;
use App\Services\DentalCaseSheetService;
use App\Support\CaseSheet\DentalCaseSheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One section of the dental case sheet (the presenting complaint, the medical
 * history, the extra-oral examination...), drawn from its definition in
 * App\Support\CaseSheet\DentalCaseSheet: what was written, and a compact form
 * to write it. A section with "normal" takes one tap; a section of the
 * patient's own history takes one tap to confirm it is still correct.
 *
 * Thin: the rules and the versions are CaseSheetService's.
 *
 * @property-read array<string,mixed> $definition
 * @property-read array<string,mixed> $row
 * @property-read Patient $patient
 */
class CaseSheetSection extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    #[Locked]
    public string $section;

    public bool $editing = false;

    /** @var array<string,mixed> */
    public array $form = [];

    /** @var array<string,string> each list (allergies, conditions) as the form showed it */
    #[Locked]
    public array $seen = [];

    /** The version the form was opened on: a save against an older one is refused. */
    #[Locked]
    public int $baseVersion = 0;

    public function mount(int $visitId, string $section): void
    {
        $this->visitId = $visitId;
        abort_if(DentalCaseSheet::section($section) === null, 404);
        $this->section = $section;
        $this->authorize('view', $this->visit);
        abort_unless(DentalCaseSheetService::canRead(auth()->user()), 403);
        if ($section === 'financial') {
            abort_unless(auth()->user()->can('billing.view'), 403);
        }
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function definition(): array
    {
        return DentalCaseSheet::section($this->section) ?? [];
    }

    #[Computed]
    public function patient(): Patient
    {
        return $this->visit->patient()->withTrashed()->firstOrFail();
    }

    /** @return array<string,mixed> the section as it stands */
    #[Computed]
    public function row(): array
    {
        $service = app(CaseSheetService::class);
        if ($this->definition['scope'] === 'patient') {
            $row = $service->patientHistory($this->patient)[$this->section];
            $row['asked_this_visit'] = $service->confirmedOn($this->patient, $this->section, $this->visit);

            return $row;
        }

        return $service->sections($this->visit)[$this->section] ?? ['data' => [], 'version' => 0, 'recorded_at' => null, 'recorded_by' => null];
    }

    /** @return array<string,mixed>|null billing's own figures, for the financial history */
    #[Computed]
    public function money(): ?array
    {
        return $this->section === 'financial' ? app(DentalCaseSheetService::class)->financial($this->patient) : null;
    }

    public function canWrite(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('visits.diagnose') || ($this->section === 'financial' && $user->can('billing.manage')));
    }

    /** Another part of the case sheet changed what this one shows. */
    #[On('dental-changed')]
    public function refresh(): void
    {
        unset($this->row, $this->money);
    }

    public function edit(): void
    {
        abort_unless($this->canWrite(), 403);
        $data = $this->row['data'];
        $this->baseVersion = (int) $this->row['version'];

        // A first complaint starts from what reception wrote; first
        // instructions start ticked from what was done today.
        if ($this->baseVersion === 0 && $this->section === 'complaint' && filled($this->visit->complaints)) {
            $data['notes'] = $this->visit->complaints;
        }
        if ($this->baseVersion === 0 && $this->section === 'instructions') {
            $data['templates'] = app(DentalCaseSheetService::class)->suggestedInstructions($this->visit);
        }

        $form = [];
        foreach ($this->definition['fields'] as $key => $field) {
            $value = $data[$key] ?? null;
            $form[$key] = match ($field['type']) {
                'chips' => array_values((array) ($value ?? [])),
                'list' => implode(', ', (array) ($value ?? [])),
                default => $value ?? '',
            };
        }
        if (! empty($this->definition['normal'])) {
            $form['normal'] = ! empty($data['normal']);
        }
        $this->form = $form;
        $this->seen = array_filter($form, fn ($v, $k) => ($this->definition['fields'][$k]['type'] ?? null) === 'list', ARRAY_FILTER_USE_BOTH);
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->form = [];
        $this->resetErrorBag();
    }

    public function save(CaseSheetService $service): void
    {
        abort_unless($this->canWrite(), 403);
        $this->authorize('view', $this->visit);

        try {
            if ($this->definition['scope'] === 'patient') {
                $service->savePatientHistory($this->patient, $this->section, $this->form, $this->visit, auth()->user(), $this->baseVersion, null, $this->seen);
            } else {
                $service->save($this->visit, $this->section, $this->form, auth()->user(), $this->baseVersion);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'section' ? 'form' : 'form.'.$key, $messages[0]);
            }

            return;
        }

        $this->editing = false;
        $this->form = [];
        $this->after($this->definition['label'].' saved.');
    }

    /** One tap: nothing abnormal. */
    public function normal(CaseSheetService $service): void
    {
        abort_unless($this->canWrite(), 403);
        try {
            $service->markNormal($this->visit, $this->section, auth()->user(), (int) $this->row['version']);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: (string) collect($e->errors())->flatten()->first(), type: 'error');

            return;
        }
        $this->after($this->definition['label'].': normal.');
    }

    /** One tap: asked again today, nothing has changed. */
    public function confirm(CaseSheetService $service): void
    {
        abort_unless($this->canWrite() && $this->definition['scope'] === 'patient', 403);
        try {
            $service->confirmPatientHistory($this->patient, $this->section, $this->visit, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: (string) collect($e->errors())->flatten()->first(), type: 'error');

            return;
        }
        $this->after($this->definition['label'].' confirmed for this visit.');
    }

    private function after(string $message): void
    {
        unset($this->row, $this->money, $this->patient);
        $this->dispatch('toast', message: $message, type: 'success');
        // The alert bar (red flags) and the header (doctor) read what changed.
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        return view('livewire.visits.panels.case-sheet-section', [
            'lines' => CaseSheetService::lines($this->section, $this->row['data']),
            'asksPregnancy' => CaseSheetService::asksPregnancy($this->patient),
        ]);
    }
}
