<?php

namespace App\Livewire\Patients\Panels;

use App\Http\Requests\PatientDependentRequest;
use App\Models\Patient;
use App\Models\PatientDependent;
use App\Services\PatientService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Guardian ⇄ dependent links (plan D6: the old form asked the user to paste a
 * raw patient UUID; this panel searches the tenant's patients as you type and
 * links the one you pick). Self-links and duplicates are refused by
 * PatientService and rendered inline.
 */
#[Lazy]
class Dependents extends Component
{
    use AuthorizesRequests, InteractsWithPatient;

    public bool $showLink = false;

    /** Typeahead term for the dependent search (never persisted). */
    public string $query = '';

    public ?string $dependent_uuid = null;

    public string $relationship = 'child';

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;

        $this->authorize('view', $this->patient);
    }

    /** @return Collection<int, PatientDependent> */
    #[Computed]
    public function links(): Collection
    {
        return $this->patient->dependents()->with('dependent')->get();
    }

    /** @return Collection<int, PatientDependent> */
    #[Computed]
    public function guardians(): Collection
    {
        return $this->patient->guardianLinks()->with('guardian')->get();
    }

    /**
     * Async patient typeahead — tenant-scoped, capped at 20 rows, never the
     * whole table (plan L/house rule 6), and never this patient itself.
     *
     * @return BaseCollection<int, Patient>
     */
    #[Computed]
    public function results(): BaseCollection
    {
        $term = trim($this->query);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Patient::search(mb_substr($term, 0, 100))
            ->whereKeyNot($this->patientId)
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'uuid', 'patient_no', 'first_name', 'last_name']);
    }

    /** The patient currently picked in the typeahead, if any. */
    #[Computed]
    public function picked(): ?Patient
    {
        return $this->dependent_uuid
            ? Patient::where('uuid', $this->dependent_uuid)->first()
            : null;
    }

    /** @return list<string> */
    #[Computed]
    public function relationships(): array
    {
        return PatientDependent::RELATIONSHIPS;
    }

    public function openLink(): void
    {
        $this->authorize('manageDependents', $this->patient);

        $this->reset(['query', 'dependent_uuid', 'relationship']);
        $this->resetErrorBag();
        $this->showLink = true;
    }

    public function select(string $uuid): void
    {
        $this->authorize('manageDependents', $this->patient);

        $this->dependent_uuid = $uuid;
        $this->query = '';
        $this->resetErrorBag('dependent_uuid');
        unset($this->results, $this->picked);
    }

    public function clearSelection(): void
    {
        $this->dependent_uuid = null;
        unset($this->picked);
    }

    public function link(PatientService $service): void
    {
        $this->authorize('manageDependents', $this->patient);

        $data = $this->validate(PatientDependentRequest::rulesFor());

        try {
            $dependent = $service->linkDependent($this->patient, $data['dependent_uuid'], $data['relationship']);
        } catch (DomainException $e) {
            $this->addError('dependent_uuid', $e->getMessage());

            return;
        }

        $this->showLink = false;
        $name = $dependent->dependent->full_name;
        $this->reset(['query', 'dependent_uuid', 'relationship']);
        unset($this->links, $this->picked, $this->results);

        $this->dispatch('toast', message: "{$name} linked as {$data['relationship']}.", type: 'success');
        $this->dispatch('patient-updated');
    }

    public function unlink(int $linkId, PatientService $service): void
    {
        $this->authorize('manageDependents', $this->patient);

        $service->unlinkDependent($this->patient, $linkId);

        unset($this->links);

        $this->dispatch('toast', message: 'Dependent unlinked.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.panels.dependents');
    }
}
