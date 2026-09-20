<?php

namespace App\Livewire\Patients;

use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The patient record — a Detail workspace (plan §4.4): a read-only summary
 * column plus five #[Lazy] child panels (cards, documents, dependents,
 * insurances, treatments) that each own their data, their abilities and their
 * writes. Replaces admin/patients/show.blade.php and its 12 classic POST forms.
 *
 * Thin (house rule 4): mount/render authorize, the only action here archives
 * the patient through PatientService and redirects to the index.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Patient $patient;

    public function mount(Patient $patient): void
    {
        $this->authorize('view', $patient);

        $this->patient = $patient;
    }

    /** A panel changed something the summary mirrors — re-read the row. */
    #[On('patient-updated')]
    public function refreshPatient(): void
    {
        $this->patient = $this->patient->fresh() ?? $this->patient;
    }

    public function archive(PatientService $service)
    {
        $this->authorize('delete', $this->patient);

        $number = $this->patient->patient_no;
        $service->archive($this->patient);

        session()->flash('success', "Patient {$number} archived.");

        return $this->redirect(route('admin.patients.index'), navigate: true);
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        $this->patient->loadMissing(['district', 'registeredBy']);

        return view('livewire.patients.show')->title($this->patient->full_name);
    }
}
