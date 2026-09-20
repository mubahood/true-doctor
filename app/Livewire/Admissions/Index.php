<?php

namespace App\Livewire\Admissions;

use App\Enums\AdmissionStatus;
use App\Http\Requests\AdmissionRequest;
use App\Livewire\Concerns\AdmitsPatients;
use App\Livewire\Concerns\MovesPatientsBetweenBeds;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedTransfer;
use App\Models\Patient;
use App\Models\User;
use App\Services\AdmissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admissions list — active cohort by default + an AJAX slide-over to admit a
 * patient to an available bed. Admission runs through AdmissionService (bed
 * locking, occupancy, ledger); this component authorizes, validates
 * (AdmissionRequest::rulesFor) and reports. Patient and bed are picked through
 * <livewire:ui.select-search>, so neither table is queried in full (C4/D3/L1).
 *
 * A row opens the stay over the list — who, where, how long, what it has cost
 * so far — because those are asked of row after row and the workspace is a
 * page-load each time. Transfer and discharge are offered from inside it, and
 * are the SAME actions the workspace and the occupancy board run.
 *
 * @property-read Admission|null $peeked
 * @property-read Collection<int,BedTransfer> $peekedTransfers
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    // `create` and `save` are what the dialog and its tests have always called
    // the two halves of admitting somebody; the trait names them for what they
    // are, and the aliases keep the screen's own vocabulary.
    use AdmitsPatients {
        openAdmit as create;
        admit as save;
    }

    use AuthorizesRequests, PeeksRecords, WithTable;
    use MovesPatientsBetweenBeds {
        openTransfer as private openTransferDialog;
        openDischarge as private openDischargeDialog;
    }

    /** Modal fields a <livewire:ui.select-search> child may set. */
    private const PICKERS = ['patient_id', 'bed_id', 'visit_id', 'admitting_doctor_id'];

    #[Url(history: true)]
    public string $status = '';

    /** A <livewire:ui.select-search> child reports a pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = null;
        }
    }

    // ── Reading one stay over the list ───────────────────────────────────

    protected function peekModel(): string
    {
        return Admission::class;
    }

    protected function peekRelations(): array
    {
        return ['patient', 'bed.ward', 'admittingDoctor', 'visit'];
    }

    protected function peekCaches(): array
    {
        return ['peekedTransfers'];
    }

    /**
     * Where this patient has been moved.
     *
     * @return Collection<int,BedTransfer>
     */
    #[Computed]
    public function peekedTransfers(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : BedTransfer::with(['fromBed.ward', 'toBed.ward'])
                ->where('admission_id', $this->peekId)
                ->latest('id')
                ->limit(5)
                ->get();
    }

    /** What the stay has run up so far, at the rate of the bed they are in. */
    public function accruedSoFar(Admission $admission): string
    {
        // bed_id is nullable — discharge() already reads it as such — so the
        // absence is checked on the column, not on the relation.
        $rate = $admission->bed_id === null ? '0.00' : (string) $admission->bed->daily_charge;

        return bcmul($rate, (string) $admission->nights(), 2);
    }

    protected function movingAdmission(): ?Admission
    {
        return $this->peeked;
    }

    /** A new patient in a bed changes the list and the occupancy behind it. */
    protected function afterAdmitting(\App\Models\Admission $admission): void
    {
        $this->resetPage();
    }

    protected function afterMovingPatient(): void
    {
        $this->forgetPeeked();
        $this->closePeek();
    }

    /**
     * Hide the quick view but remember the stay it was showing: closePeek()
     * would forget it, and the stay is what these two dialogs act on.
     */
    public function openTransfer(): void
    {
        $this->openTransferDialog();
        $this->showPeek = false;
    }

    public function openDischarge(): void
    {
        $this->openDischargeDialog();
        $this->showPeek = false;
    }

    // `doctors` was an uncapped `User::currentHospital()->get()` on every
    // render, drawn into a <select>. It is a picker now (docs/visits.md).

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return AdmissionStatus::options();
    }

    public function render()
    {
        $this->authorize('viewAny', Admission::class);

        $admissions = Admission::with(['patient', 'bed.ward'])
            ->when(
                $this->status !== '',
                fn (Builder $q) => $q->where('status', $this->status),
                fn (Builder $q) => $q->where('status', AdmissionStatus::Admitted->value),
            )
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('patient_no', 'like', "%{$this->search}%")))
            ->latest('admitted_at')
            ->paginate($this->perPage);

        return view('livewire.admissions.index', ['rows' => $admissions])->title('Admissions');
    }
}
