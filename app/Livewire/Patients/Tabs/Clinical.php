<?php

namespace App\Livewire\Patients\Tabs;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The clinical history: diagnoses, medication, lab results, imaging reports,
 * vitals and admissions. Lab, imaging and admissions also depend on the
 * practice using that module (a dental clinic has no admissions).
 */
#[Lazy]
class Clinical extends Component
{
    use PatientTab;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->requireSection('clinical');
    }

    /** @return array<string,list<array<string,mixed>>> */
    #[Computed]
    public function data(): array
    {
        $service = $this->summaryService();
        $has = fn (string $s): bool => in_array($s, $this->sections, true);

        return [
            'diagnoses' => $service->diagnosisRows($this->patient, 25),
            'medication' => $service->currentMedication($this->patient),
            'prescriptions' => $service->prescriptions($this->patient, 10),
            'vitals' => $service->vitals($this->patient, 8),
            'lab' => $has('lab') ? $service->labRows($this->patient, 10) : [],
            'imaging' => $has('imaging') ? $service->imagingRows($this->patient, 10) : [],
            'admissions' => $has('admissions') ? $service->admissionRows($this->patient, 10) : [],
        ];
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->requireSection('clinical');

        return view('livewire.patients.tabs.clinical');
    }
}
