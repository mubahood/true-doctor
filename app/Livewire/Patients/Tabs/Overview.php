<?php

namespace App\Livewire\Patients\Tabs;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The landing tab: a timeline of everything that happened to the patient, and
 * the most recent few of each kind. Each block shows only what this person may
 * see; "view all" moves the page to the tab that holds the rest.
 */
#[Lazy]
class Overview extends Component
{
    use PatientTab;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->authorize('view', $this->patient);
    }

    /** @return array<string,list<array<string,mixed>>> */
    #[Computed]
    public function recent(): array
    {
        return $this->summaryService()->recent($this->patient, $this->sections, 5);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function activity(): array
    {
        // A wider net than the 5-per-kind blocks, so the timeline reaches back.
        return $this->summaryService()->activity(
            $this->summaryService()->recent($this->patient, $this->sections, 10), 15);
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.tabs.overview');
    }
}
