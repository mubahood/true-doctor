<?php

namespace App\Livewire\Patients\Panels;

use App\Http\Requests\TreatmentRecordRequest;
use App\Models\TreatmentRecord;
use App\Services\TreatmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Treatment records for one patient. The record, its photos and the private
 * disk writes all live in TreatmentService; photos are uploaded through
 * Livewire (with per-file previews) instead of a multipart page POST.
 */
#[Lazy]
class Treatments extends Component
{
    use AuthorizesRequests, InteractsWithPatient, WithFileUploads;

    public bool $showAdd = false;

    public string $procedure = '';

    public ?string $description = null;

    public ?string $performed_at = null;

    /** @var array<int, mixed> */
    public array $photos = [];

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;

        // Resolves the patient through the tenant scope (404 cross-tenant) …
        $this->authorize('view', $this->patient);
        // … and the panel itself needs treatment visibility.
        $this->authorize('viewAny', TreatmentRecord::class);
    }

    /** @return Collection<int, TreatmentRecord> */
    #[Computed]
    public function records(): Collection
    {
        return $this->patient->treatmentRecords()->with(['photos', 'performedBy'])->get();
    }

    public function openAdd(): void
    {
        $this->authorize('create', TreatmentRecord::class);

        $this->reset(['procedure', 'description', 'performed_at', 'photos']);
        $this->resetErrorBag();
        $this->showAdd = true;
    }

    public function add(TreatmentService $service): void
    {
        $this->authorize('create', TreatmentRecord::class);

        $rules = TreatmentRecordRequest::rulesFor();
        unset($rules['visit_id']);
        $data = $this->validate($rules);

        $service->create(
            $this->patient,
            [
                'procedure' => $data['procedure'],
                'description' => ($data['description'] ?? null) ?: null,
                'performed_at' => ($data['performed_at'] ?? null) ?: null,
            ],
            array_values($this->photos),
            Auth::id(),
        );

        $this->showAdd = false;
        $this->reset(['procedure', 'description', 'performed_at', 'photos']);
        unset($this->records);

        $this->dispatch('toast', message: 'Treatment record added.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function delete(int $recordId, TreatmentService $service): void
    {
        /** @var TreatmentRecord $record */
        $record = $this->patient->treatmentRecords()->whereKey($recordId)->firstOrFail();
        $this->authorize('delete', $record);

        $service->delete($record);

        unset($this->records);

        $this->dispatch('toast', message: 'Treatment record removed.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function render()
    {
        $this->authorize('viewAny', TreatmentRecord::class);

        return view('livewire.patients.panels.treatments');
    }
}
