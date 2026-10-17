<?php

namespace App\Livewire\Patients\Tabs;

use App\Services\Audit\RecordAccessLog;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who opened this record, and who printed it (roadmap 07 §3, docs/audit.md).
 * The owner's question, so it is gated on `manage-settings` like the audit
 * log itself.
 */
#[Lazy]
class Access extends Component
{
    use PatientTab, WithPagination;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->guard();
    }

    private function guard(): void
    {
        $this->authorize('view', $this->patient);
        $this->authorize('manage-settings');
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
        $this->guard();

        return view('livewire.patients.tabs.access', [
            'rows' => app(RecordAccessLog::class)->query(['patient' => $this->patient->id])->paginate(15),
        ]);
    }
}
