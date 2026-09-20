<?php

namespace App\Livewire\Visits\Panels;

use App\Http\Requests\LabOrderRequest;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Services\LabService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Raising lab tests on the visit. Ordering and billing are one atomic call
 * into LabService (the panel never writes a row itself), so a lab order and its
 * charge can never diverge. Because an order also creates a billable line, the
 * panel dispatches `visit-updated` so the Charges panel re-reads.
 *
 * The whole panel is gated on lab.order — matching the classic page, where the
 * card simply did not render for anyone else.
 */
#[Lazy]
class LabOrders extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    /** @var array<int, int|string> */
    public array $test_ids = [];

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
        abort_unless(Auth::user()?->can('lab.order'), 403);
    }

    /** @return Collection<int, LabTest> */
    #[Computed]
    public function tests(): Collection
    {
        return LabTest::where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']);
    }

    /** @return Collection<int, LabOrder> */
    #[Computed]
    public function orders(): Collection
    {
        return $this->visit->labOrders()->with('items')->latest()->get();
    }

    public function openOrder(): void
    {
        $this->authorize('view', $this->visit);
        $this->reset(['test_ids', 'clinical_notes']);
        $this->resetErrorBag();
        $this->showOrder = true;
    }

    public function order(LabService $service): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeOrder();

        // Checkbox arrays arrive sparse and as strings; normalise before validating.
        $this->test_ids = array_values(array_filter(array_map('intval', $this->test_ids)));
        if (is_string($this->clinical_notes) && trim($this->clinical_notes) === '') {
            $this->clinical_notes = null;
        }

        $data = $this->validate(LabOrderRequest::rulesFor());

        $service->order($visit, $data['test_ids'], $data['clinical_notes'] ?? null, Auth::id());

        $this->reset(['test_ids', 'clinical_notes']);
        $this->showOrder = false;
        unset($this->visit, $this->orders);

        $this->dispatch('toast', message: 'Lab tests ordered and billed.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);
        $this->authorizeOrder();

        return view('livewire.visits.panels.lab-orders');
    }
}
