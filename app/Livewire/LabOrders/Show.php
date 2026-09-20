<?php

namespace App\Livewire\LabOrders;

use App\Enums\LabOrderStatus;
use App\Http\Requests\LabResultRequest;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Services\LabService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Lab order detail (Detail shape, plan §4.4) — replaces admin/lab-orders/show.blade.php
 * and LabOrderController@show/transition/result. The PDF stays a controller download.
 *
 * Results are entered inline: `wire:model.blur` on a per-item cell fires
 * updatedResults(), which validates that one item with LabResultRequest's rules
 * and writes it through LabService. The status machine is the enum's — an
 * illegal move raises a RuntimeException from the service and becomes a toast.
 *
 * @property-read LabOrder $order
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public int $orderId;

    /**
     * Per-item result draft, keyed by lab_order_item id:
     * [id => ['result_value' => ?string, 'result_flag' => ?string, 'result_notes' => ?string]].
     * Client-editable, so every write is re-validated and the item id is checked
     * against this order's items before it reaches the service (house rule 5).
     *
     * @var array<int,array<string,string|null>>
     */
    public array $results = [];

    public function mount(string $labOrder): void
    {
        abort_unless(auth()->user()?->can('lab.view'), 403);

        // Tenant-scoped resolution: another hospital's uuid is a 404.
        $order = LabOrder::where('uuid', $labOrder)->firstOrFail();
        $this->orderId = $order->id;

        foreach ($order->items as $item) {
            $this->results[$item->id] = [
                'result_value' => $item->result_value,
                'result_flag' => $item->result_flag?->value,
                'result_notes' => $item->result_notes,
            ];
        }
    }

    #[Computed]
    public function order(): LabOrder
    {
        return LabOrder::with(['items', 'patient', 'orderedBy', 'visit'])->findOrFail($this->orderId);
    }

    /** Advance (or cancel) the order through the LabOrderStatus machine. */
    public function transition(string $status, LabService $service): void
    {
        abort_unless(auth()->user()?->can('lab.process'), 403);

        $to = LabOrderStatus::tryFrom($status);
        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown lab order status.', type: 'error');

            return;
        }

        try {
            $service->transition($this->order, $to, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->order);
        $this->dispatch('toast', message: "Lab order moved to {$to->label()}.", type: 'success');
    }

    /** wire:model.blur hook — $key looks like "12.result_value". */
    public function updatedResults(mixed $value, string $key): void
    {
        $itemId = (int) explode('.', $key)[0];
        $this->saveResult($itemId);
    }

    public function saveResult(int $itemId): void
    {
        abort_unless(auth()->user()?->can('lab.process'), 403);

        /** @var LabOrderItem|null $item */
        $item = $this->order->items->firstWhere('id', $itemId);
        if ($item === null) {
            return;
        }

        $rules = [];
        foreach (LabResultRequest::rulesFor() as $field => $rule) {
            $rules["results.{$itemId}.{$field}"] = $rule;
        }
        $this->validate($rules);

        app(LabService::class)->recordResult($item, [
            'result_value' => $this->results[$itemId]['result_value'] ?: null,
            'result_flag' => $this->results[$itemId]['result_flag'] ?: null,
            'result_notes' => $this->results[$itemId]['result_notes'] ?: null,
        ], Auth::id());

        unset($this->order);
        $this->dispatch('toast', message: "Result saved for {$item->name}.", type: 'success');
    }

    public function render()
    {
        abort_unless(auth()->user()?->can('lab.view'), 403);

        return view('livewire.lab-orders.show', ['order' => $this->order])->title('Lab order');
    }
}
