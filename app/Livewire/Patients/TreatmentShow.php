<?php

namespace App\Livewire\Patients;

use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Services\TreatmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One treatment record (Detail shape, plan §4.4) — replaces
 * admin/treatments/show.blade.php and TreatmentRecordController@show. The
 * photo stream stays a classic GET (TreatmentRecordController@photo): the
 * images live on the private disk and are never a public URL, so the thumbnails
 * are plain links out of the SPA.
 *
 * Deleting a record removes its files through TreatmentService before the row
 * goes, then flashes and navigates back to the patient workspace.
 *
 * @property-read Patient $patient
 * @property-read TreatmentRecord $record
 */
#[Layout('layouts.admin')]
class TreatmentShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $patientId;

    #[Locked]
    public int $recordId;

    public function mount(string $patient, string $treatment): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $patientModel = Patient::where('uuid', $patient)->firstOrFail();
        $record = TreatmentRecord::where('uuid', $treatment)->firstOrFail();

        $this->authorize('view', $record);
        abort_unless($record->patient_id === $patientModel->id, 404);

        $this->patientId = $patientModel->id;
        $this->recordId = $record->id;
    }

    #[Computed]
    public function patient(): Patient
    {
        return Patient::findOrFail($this->patientId);
    }

    #[Computed]
    public function record(): TreatmentRecord
    {
        return TreatmentRecord::with(['photos', 'performedBy'])->findOrFail($this->recordId);
    }

    public function delete(TreatmentService $service)
    {
        $record = $this->record;
        $this->authorize('delete', $record);

        $patient = $this->patient;
        $service->delete($record);

        session()->flash('success', 'Treatment record removed.');

        return $this->redirect(route('admin.patients.show', $patient), navigate: true);
    }

    /** Render one `meta` entry of the specialty JSON for the definition list. */
    public static function metaValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    public function render()
    {
        $record = $this->record;
        $this->authorize('view', $record);

        return view('livewire.patients.treatment-show', [
            'patient' => $this->patient,
            'record' => $record,
        ])->title($record->procedure);
    }
}
