<?php

namespace App\Livewire\Patients;

use App\Enums\InvoiceStatus;
use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Livewire\Concerns\InteractsWithPatientForm;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\District;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\PatientService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Patients list — a Livewire table (search / status filter / sort / paginate) +
 * an AJAX modal (slide-over) register/edit form. All over AJAX, no full reload.
 * Thin: authorizes via PatientPolicy, validates against the shared
 * PatientRequest rules and persists through PatientService.
 *
 * A row is six columns of a record that has forty fields. The things a desk
 * actually asks for — allergies, next of kin, whether they owe anything, when
 * they were last seen — open over the list; the record itself is a workspace
 * with its own panels and stays a page.
 *
 * @property-read Patient|null $peeked
 * @property-read Collection<int,Visit> $peekedVisits
 * @property-read array<string,int|string|null> $peekedFigures
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, InteractsWithPatientForm, PeeksRecords, WithTable;

    // Named $statusFilter (not $status) to avoid clashing with the patient
    // form's own $status field from InteractsWithPatientForm.
    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public bool $showForm = false;

    #[\Livewire\Attributes\Locked]
    public ?int $editingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Patient::class);
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function sortableFields(): array
    {
        return ['patient_no', 'first_name', 'last_name', 'status', 'created_at'];
    }

    public function create(): void
    {
        $this->authorize('create', Patient::class);
        $this->editingId = null;
        $this->resetPatientForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $patient = Patient::findOrFail($id);
        $this->authorize('update', $patient);
        $this->editingId = $patient->id;
        $this->fillPatientForm($patient);
        $this->showForm = true;
    }

    public function save(PatientService $service): void
    {
        $patient = $this->editingId ? Patient::findOrFail($this->editingId) : null;

        $patient
            ? $this->authorize('update', $patient)
            : $this->authorize('create', Patient::class);

        try {
            $saved = $this->persistPatient($service, $patient);
        } catch (PlanLimitExceededException $e) {
            $this->addError('first_name', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->showForm = false;
        $this->editingId = null;
        $this->resetPatientForm();
        $this->dispatch('toast', message: $patient ? 'Patient updated.' : "Patient registered — {$saved->patient_no}.", type: 'success');
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Patient::class;
    }

    protected function peekRelations(): array
    {
        return ['district', 'registeredBy'];
    }

    protected function peekCaches(): array
    {
        return ['peekedVisits', 'peekedFigures'];
    }

    /**
     * When they were last here, and for what.
     *
     * @return Collection<int,Visit>
     */
    #[Computed]
    public function peekedVisits(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : Visit::with('doctor')->where('patient_id', $this->peekId)->latest('id')->limit(4)->get();
    }

    /**
     * What they have been here for and what they owe.
     *
     * The unpaid figure is the one a desk actually wants before it books
     * anything else, and it was not on this screen at all.
     *
     * @return array<string,int|string|null>
     */
    #[Computed]
    public function peekedFigures(): array
    {
        if ($this->peekId === null) {
            return ['visits' => 0, 'owed' => '0.00', 'lastSeen' => null];
        }

        // Only invoices that are actually outstanding: a draft has not been
        // asked for yet, and a void one is not owed at all.
        $owed = Invoice::where('patient_id', $this->peekId)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->sum('balance');

        return [
            'visits' => Visit::where('patient_id', $this->peekId)->count(),
            'owed' => number_format((float) $owed, 2, '.', ''),
            'lastSeen' => Visit::where('patient_id', $this->peekId)->max('created_at'),
        ];
    }

    /**
     * Edit the patient being read.
     *
     * The peek closes first: two dialogs stacked on each other is how somebody
     * ends up editing one record while looking at another.
     */
    public function editPeeked(): void
    {
        $id = $this->peekId;
        $this->closePeek();

        if ($id !== null) {
            $this->edit($id);
        }
    }

    public function render()
    {
        $this->authorize('viewAny', Patient::class);

        $patients = $this->applySort(
            Patient::query()
                ->search($this->search)
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        )->paginate($this->perPage);

        return view('livewire.patients.index', [
            'patients' => $patients,
            'statuses' => PatientStatus::options(),
            'districts' => District::orderBy('name')->get(['id', 'name']),
            'sexes' => PatientSex::options(),
            'bloodTypes' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        ])->title('Patients');
    }
}
