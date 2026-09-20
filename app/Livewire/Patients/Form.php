<?php

namespace App\Livewire\Patients;

use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Livewire\Concerns\InteractsWithPatientForm;
use App\Models\District;
use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Full-page create/edit for a patient (deep-linkable — used by the patient
 * detail page's Edit action). Shares its field set, validation and persistence
 * with the index modal via InteractsWithPatientForm. Thin (A1): authorize,
 * validate against PatientRequest rules, delegate to PatientService.
 */
#[Layout('layouts.admin')]
class Form extends Component
{
    use AuthorizesRequests, InteractsWithPatientForm;

    #[\Livewire\Attributes\Locked]
    public ?Patient $patient = null;

    public function mount(?Patient $patient = null): void
    {
        if ($patient && $patient->exists) {
            $this->authorize('update', $patient);
            $this->patient = $patient;
            $this->fillPatientForm($patient);
        } else {
            $this->authorize('create', Patient::class);
        }
    }

    public function save(PatientService $service)
    {
        $this->patient
            ? $this->authorize('update', $this->patient)
            : $this->authorize('create', Patient::class);

        try {
            $patient = $this->persistPatient($service, $this->patient);
        } catch (PlanLimitExceededException $e) {
            $this->addError('first_name', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        session()->flash('success', $this->patient
            ? 'Patient updated.'
            : "Patient registered — {$patient->patient_no}.");

        return $this->redirect(route('admin.patients.show', $patient), navigate: true);
    }

    public function render()
    {
        return view('livewire.patients.form', [
            'districts' => District::orderBy('name')->get(['id', 'name']),
            'sexes' => PatientSex::options(),
            'statuses' => PatientStatus::options(),
            'bloodTypes' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        ])->title($this->patient ? 'Edit patient' : 'Register patient');
    }
}
