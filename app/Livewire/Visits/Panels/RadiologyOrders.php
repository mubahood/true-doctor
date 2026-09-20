<?php

namespace App\Livewire\Visits\Panels;

use App\Http\Requests\RadiologyOrderRequest;
use App\Models\RadiologyOrder;
use App\Models\RadiologyStudy;
use App\Services\RadiologyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Raising imaging studies on the visit — the radiology twin of the lab
 * panel. Ordering and billing are one atomic call into RadiologyService, and the
 * new charge wakes the Charges panel through `visit-updated`.
 *
 * The whole panel is gated on radiology.order, matching the classic page.
 */
#[Lazy]
class RadiologyOrders extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    /** @var array<int, int|string> */
    public array $study_ids = [];

    public ?string $clinical_notes = null;

    /**
     * The panel lists what has been ordered; ordering happens in a dialog off
     * the button above it. Inline, the picker made the whole visit page read
     * as a form rather than as a record.
     */
    public bool $showOrder = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        $this->authorize('view', $this->visit);
        $this->authorizeOrder();
    }

    private function authorizeOrder(): void
    {
        abort_unless(Auth::user()?->can('radiology.order'), 403);
    }

    /** @return Collection<int, RadiologyStudy> */
    #[Computed]
    public function studies(): Collection
    {
        return RadiologyStudy::where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']);
    }

    /** @return Collection<int, RadiologyOrder> */
    #[Computed]
    public function orders(): Collection
    {
        return $this->visit->radiologyOrders()->with('items')->latest()->get();
    }

    public function openOrder(): void
    {
        $this->authorize('view', $this->visit);
        $this->reset(['study_ids', 'clinical_notes']);
        $this->resetErrorBag();
        $this->showOrder = true;
    }

    public function order(RadiologyService $service): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeOrder();

        $this->study_ids = array_values(array_filter(array_map('intval', $this->study_ids)));
        if (is_string($this->clinical_notes) && trim($this->clinical_notes) === '') {
            $this->clinical_notes = null;
        }

        $data = $this->validate(RadiologyOrderRequest::rulesFor());

        $service->order($visit, $data['study_ids'], $data['clinical_notes'] ?? null, Auth::id());

        $this->reset(['study_ids', 'clinical_notes']);
        $this->showOrder = false;
        unset($this->visit, $this->orders);

        $this->dispatch('toast', message: 'Radiology studies ordered and billed.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);
        $this->authorizeOrder();

        return view('livewire.visits.panels.radiology-orders');
    }
}
