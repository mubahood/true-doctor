<?php

namespace App\Livewire\RadiologyOrders;

use App\Enums\RadiologyOrderStatus;
use App\Http\Requests\RadiologyReportRequest;
use App\Models\RadiologyOrder;
use App\Services\RadiologyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Radiology order detail (Detail shape, plan §4.4) — replaces
 * admin/radiology-orders/show.blade.php and RadiologyOrderController@show/transition/report.
 * The PDF stays a controller download.
 *
 * The narrative report (findings + impression) is a Livewire form validated with
 * RadiologyReportRequest's rules and written through RadiologyService; the status
 * machine is the enum's, and an illegal move surfaces as a toast.
 *
 * @property-read RadiologyOrder $order
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public int $orderId;

    public ?string $findings = null;

    public ?string $impression = null;

    public function mount(string $radiologyOrder): void
    {
        abort_unless(auth()->user()?->can('radiology.view'), 403);

        // Tenant-scoped resolution: another hospital's uuid is a 404.
        $order = RadiologyOrder::where('uuid', $radiologyOrder)->firstOrFail();

        $this->orderId = $order->id;
        $this->findings = $order->findings;
        $this->impression = $order->impression;
    }

    #[Computed]
    public function order(): RadiologyOrder
    {
        return RadiologyOrder::with(['items', 'patient', 'orderedBy', 'visit'])->findOrFail($this->orderId);
    }

    /** Advance (or cancel) the order through the RadiologyOrderStatus machine. */
    public function transition(string $status, RadiologyService $service): void
    {
        abort_unless(auth()->user()?->can('radiology.report'), 403);

        $to = RadiologyOrderStatus::tryFrom($status);
        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown radiology order status.', type: 'error');

            return;
        }

        try {
            $service->transition($this->order, $to, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->order);
        $this->dispatch('toast', message: "Radiology order moved to {$to->label()}.", type: 'success');
    }

    public function saveReport(RadiologyService $service): void
    {
        abort_unless(auth()->user()?->can('radiology.report'), 403);

        $data = $this->validate(RadiologyReportRequest::rulesFor());

        $service->recordReport($this->order, $data['findings'] ?: null, $data['impression'] ?: null, Auth::id());

        unset($this->order);
        $this->dispatch('toast', message: 'Report saved.', type: 'success');
    }

    public function render()
    {
        abort_unless(auth()->user()?->can('radiology.view'), 403);

        return view('livewire.radiology-orders.show', ['order' => $this->order])->title('Radiology order');
    }
}
