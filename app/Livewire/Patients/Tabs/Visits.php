<?php

namespace App\Livewire\Patients\Tabs;

use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

/** Every visit this patient has made: paginated, newest first, each row opens the visit workspace. */
#[Lazy]
class Visits extends Component
{
    use PatientTab, WithPagination;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->requireSection('visits');
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
        $this->requireSection('visits');

        return view('livewire.patients.tabs.visits', [
            'rows' => $this->summaryService()->visitsPage($this->patient, in_array('money', $this->sections, true), 15, 'visits', in_array('clinical', $this->sections, true)),
        ]);
    }
}
