<?php

namespace App\Livewire\Visits\Panels;

use App\Livewire\Concerns\WritesDosage;
use App\Models\DoseItem;
use App\Models\Prescription;
use App\Services\PrescriptionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The visit's dosage (docs/pharmacy.md, "Dosage"), a dental clinic's way of
 * prescribing: how the patient takes each medicine at home. The rows are
 * prescription lines (PrescriptionService::writeDosage), so the pharmacy
 * queue, dispensing and the bill follow the prescription flow; nothing is
 * charged here.
 */
#[Lazy]
class Dosage extends Component
{
    use AuthorizesRequests, InteractsWithVisit, WritesDosage;

    public bool $showForm = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
        $this->authorize('viewClinical', $this->visit);
    }

    /** @return Collection<int, DoseItem> every line on the visit, oldest first */
    #[Computed]
    public function doses(): Collection
    {
        return DoseItem::query()
            ->with('prescription.prescriber:id,name')
            ->whereHas('prescription', fn ($q) => $q->where('visit_id', $this->visitId))
            ->oldest('id')
            ->get();
    }

    public function openForm(): void
    {
        $this->authorize('prescribe', Prescription::class);

        $this->dosage = [self::blankDose()];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function removeDoseRow(int $index): void
    {
        unset($this->dosage[$index]);
        $this->dosage = array_values($this->dosage) ?: [self::blankDose()];
        $this->resetErrorBag();
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        $this->dosePicked($name, $id);
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        $this->dosePicked($name, null);
    }

    public function save(PrescriptionService $service): void
    {
        $this->authorize('view', $this->visit);
        $this->authorize('prescribe', Prescription::class);

        $rows = $this->validatedDosage();

        try {
            $service->writeDosage($this->visit, $rows, Auth::id());
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->showForm = false;
        $this->dosage = [];
        unset($this->doses);

        $this->dispatch('toast', message: 'Dosage saved.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function remove(int $doseId, PrescriptionService $service): void
    {
        $this->authorize('prescribe', Prescription::class);

        // The line must be on *this* visit (and, through the global scope, this tenant).
        $dose = DoseItem::query()->whereKey($doseId)
            ->whereHas('prescription', fn ($q) => $q->where('visit_id', $this->visitId))
            ->firstOrFail();

        try {
            $service->removeDose($dose);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->doses);
        $this->dispatch('toast', message: 'Medicine removed.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);
        $this->authorize('viewClinical', $this->visit);

        return view('livewire.visits.panels.dosage');
    }
}
