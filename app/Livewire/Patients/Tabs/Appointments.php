<?php

namespace App\Livewire\Patients\Tabs;

use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

/** Upcoming and past appointments, each with its status. */
#[Lazy]
class Appointments extends Component
{
    use PatientTab, WithPagination;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->requireSection('appointments');
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->requireSection('appointments');
        $service = $this->summaryService();

        return view('livewire.patients.tabs.appointments', [
            'upcoming' => $service->appointmentsPage($this->patient, true, 10, 'upcoming'),
            'past' => $service->appointmentsPage($this->patient, false, 10, 'past'),
        ]);
    }
}
