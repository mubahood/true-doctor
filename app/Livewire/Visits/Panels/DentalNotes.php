<?php

namespace App\Livewire\Visits\Panels;

use App\Services\DentalCaseSheetService;
use App\Services\DentalNotesService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A dental visit's simple notes (docs/dental.md, "Simple notes"): the
 * presenting complaint, History and Oral examination as plain text. Read on
 * the card; written in a dialog by whoever writes clinical notes.
 *
 * Thin: the versions, the carry-forward and what the visit mirrors are
 * DentalNotesService's.
 *
 * @property-read array{data:array<string,string>, version:int, recorded_at:?string, recorded_by:?string, carried:list<string>} $notes
 */
class DentalNotes extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    public bool $showForm = false;

    public ?string $complaints = null;

    /** @var array<string,string|null> */
    public array $form = [];

    /** The version the form was opened on: a save against an older one is refused. */
    #[Locked]
    public int $baseVersion = 0;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
        $this->authorize('viewClinical', $this->visit);
        abort_unless(DentalCaseSheetService::canRead(auth()->user()), 403);
    }

    /** @return array{data:array<string,string>, version:int, recorded_at:?string, recorded_by:?string, carried:list<string>} */
    #[Computed]
    public function notes(): array
    {
        return app(DentalNotesService::class)->forVisit($this->visit);
    }

    public function openForm(): void
    {
        $this->authorize('diagnose', $this->visit);
        unset($this->visit, $this->notes);

        $this->complaints = $this->visit->complaints;
        $this->form = [];
        foreach (array_keys(DentalNotesService::FIELDS) as $key) {
            $this->form[$key] = $this->notes['data'][$key] ?? '';
        }
        $this->baseVersion = $this->notes['version'];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(DentalNotesService $service): void
    {
        $this->authorize('diagnose', $this->visit);

        try {
            $service->save($this->visit, $this->form + ['complaints' => $this->complaints], auth()->user(), $this->baseVersion, null, 'form.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'notes' ? 'form' : $key, $messages[0]);
            }

            return;
        }

        unset($this->visit, $this->notes);
        $this->showForm = false;

        $this->dispatch('toast', message: 'Notes saved.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.dental-notes', ['visit' => $this->visit]);
    }
}
