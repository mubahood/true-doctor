<?php

namespace App\Livewire\Dispensations;

use App\Models\Dispensation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dispensation detail (Detail shape, plan §4.4) — read-only receipt of a
 * pharmacy hand-out. Replaces admin/dispensations/show.blade.php and
 * DispensationController@show; dispensing itself still happens on the
 * visit workspace.
 *
 * @property-read Dispensation $dispensation
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public int $dispensationId;

    public function mount(string $dispensation): void
    {
        $this->authorizeView();

        // Tenant-scoped resolution: another hospital's uuid is a 404.
        $model = Dispensation::where('uuid', $dispensation)->firstOrFail();
        $this->dispensationId = $model->id;
    }

    #[Computed]
    public function dispensation(): Dispensation
    {
        return Dispensation::with(['items.stockItem', 'patient', 'dispensedBy', 'visit'])
            ->findOrFail($this->dispensationId);
    }

    /** Pharmacy readers and dispensers may both open a dispensation. */
    private function authorizeView(): void
    {
        $user = auth()->user();

        abort_unless($user?->can('pharmacy.view') || $user?->can('pharmacy.dispense'), 403);
    }

    public function render()
    {
        $this->authorizeView();

        return view('livewire.dispensations.show', ['dispensation' => $this->dispensation])
            ->title('Dispensation');
    }
}
