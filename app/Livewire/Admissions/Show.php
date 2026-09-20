<?php

namespace App\Livewire\Admissions;

use App\Enums\AdmissionStatus;
use App\Livewire\Concerns\MovesPatientsBetweenBeds;
use App\Models\Admission;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The admission workspace (Detail shape, plan §4.4) — replaces
 * admin/admissions/show.blade.php, AdmissionController@show/@transfer/@discharge
 * and the whole of NursingController. Only the discharge-summary PDF stays a
 * controller download.
 *
 * A read-only summary column plus three #[Lazy] nursing panels (vitals, MAR,
 * notes) that each own their data and their append-only writes. Two slide-overs
 * live here:
 *   • Transfer — bed picked through <livewire:ui.select-search resource="beds-available">
 *     so the bed table is never rendered whole; AdmissionService locks both beds.
 *   • Discharge — pessimistic (it bills the stay) and guarded by wire:confirm.
 *     Plan D4: the old confirmation was an `onclick` on the button and could be
 *     bypassed by submitting the form directly; it is now wire:confirm on the
 *     Livewire action, so the server write is the thing being confirmed.
 *
 * @property-read Admission $admission
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests, MovesPatientsBetweenBeds;

    #[Locked]
    public int $admissionId;

    public function mount(string $admission): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $model = Admission::where('uuid', $admission)->firstOrFail();
        $this->authorize('view', $model);

        $this->admissionId = $model->id;
    }

    #[Computed]
    public function admission(): Admission
    {
        return Admission::with(['patient', 'bed.ward', 'admittingDoctor', 'visit', 'transfers.fromBed', 'transfers.toBed'])
            ->findOrFail($this->admissionId);
    }

    /** A <livewire:ui.select-search> child reports a bed pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name === 'to_bed_id') {
            $this->to_bed_id = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'to_bed_id') {
            $this->to_bed_id = null;
        }
    }

    /** The admission the transfer and discharge dialogs act on. */
    protected function movingAdmission(): ?Admission
    {
        return $this->admission;
    }

    protected function afterMovingPatient(): void
    {
        unset($this->admission);
    }

    public function render()
    {
        $admission = $this->admission;
        $this->authorize('view', $admission);

        return view('livewire.admissions.show', [
            'admission' => $admission,
            'outcomes' => AdmissionStatus::outcomes(),
        ])->title($admission->patient->full_name);
    }
}
