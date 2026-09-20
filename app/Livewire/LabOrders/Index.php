<?php

namespace App\Livewire\LabOrders;

use App\Enums\LabOrderStatus;
use App\Http\Requests\LabResultRequest;
use App\Livewire\Concerns\ChargesWorkDone;
use App\Livewire\Concerns\CollectsAttachments;
use App\Livewire\Concerns\WithTable;
use App\Models\Contracts\HoldsAttachments;
use App\Models\LabOrder;
use App\Services\LabService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The lab bench: what has been asked for, and what is still owed back.
 *
 * This was a flat list of orders behind an eye icon — every result took two
 * page loads to enter, nothing said how long anything had been waiting, and
 * there was nowhere at all to put the report the analyser printed. A bench
 * works to one question, "what is outstanding", and the answer has to be on
 * the screen it is answered from.
 *
 * So: the same shape as the visit module (docs/visits.md). What is waiting
 * across the top, one row per order, and the result entered in a dialog over
 * the list — values, flags, and THE FILES, which is what the machine actually
 * produces (App\Livewire\Concerns\CollectsAttachments).
 *
 * @property-read array{ordered:int,collected:int,processing:int,completed:int,oldest:int|null} $waiting
 * @property-read LabOrder|null $working
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use ChargesWorkDone, CollectsAttachments, WithTable;

    /** Waiting longer than this is the thing a bench needs to see. */
    public const OLD_HOURS = 24;

    #[Url(history: true, except: '')]
    public string $status = '';

    /** Only what is still owed back — the bench's own default reading. */
    #[Url(history: true, except: false)]
    public bool $outstanding = false;

    // ── The result dialog ────────────────────────────────────────────────
    public bool $showResult = false;

    public ?int $resultId = null;

    /**
     * Per-test draft, keyed by lab_order_item id.
     *
     * @var array<int,array<string,string|null>>
     */
    public array $results = [];

    public function mount(): void
    {
        $this->authorizeView();
    }

    protected function resetsPage(): array
    {
        return ['status', 'outstanding'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['created_at', 'status'];
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->can('lab.view') === true, 403);
    }

    protected function assertMayAttach(): void
    {
        abort_unless(Auth::user()?->can('lab.process') === true, 403);
    }

    protected function attachmentOwner(): ?HoldsAttachments
    {
        return $this->working;
    }

    protected function afterAttaching(): void
    {
        unset($this->working);
    }

    protected function assertMayCharge(): void
    {
        $this->assertMayAttach();
    }

    /**
     * The visit order this work is billed through.
     *
     * It has existed since the work was ordered — LabOrderService places one
     * of type Lab with this record as its `subject`, and bills the tests
     * onto it. What the bench USED goes on the same order, so one piece of
     * work is one set of charges rather than two.
     */
    protected function chargeTo(): ?\App\Models\Order
    {
        $work = $this->working;

        if ($work === null || $work->visit === null) {
            return null;
        }

        return app(\App\Services\OrderService::class)->forSubject(
            $work,
            $work->visit,
            \App\Enums\OrderType::Lab,
            'Lab tests',
            \Illuminate\Support\Facades\Auth::id(),
        );
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        $this->providedPicked($name, $id);
    }

    // ── What is outstanding ──────────────────────────────────────────────

    /**
     * What the bench owes back, and how old the oldest of it is.
     *
     * A count of orders is not a workload. "Four collected, the oldest waiting
     * two days" is, and it was nowhere on the page.
     *
     * @return array{ordered:int,collected:int,processing:int,completed:int,oldest:int|null}
     */
    #[Computed]
    public function waiting(): array
    {
        $counts = LabOrder::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldest = LabOrder::query()
            ->whereIn('status', $this->outstandingStatuses())
            ->min('created_at');

        return [
            'ordered' => (int) ($counts[LabOrderStatus::Ordered->value] ?? 0),
            'collected' => (int) ($counts[LabOrderStatus::Collected->value] ?? 0),
            'processing' => (int) ($counts[LabOrderStatus::Processing->value] ?? 0),
            'completed' => (int) LabOrder::whereDate('completed_at', now()->toDateString())->count(),
            'oldest' => $oldest === null ? null : (int) \Illuminate\Support\Carbon::parse($oldest)->diffInHours(now()),
        ];
    }

    /** @return list<string> the statuses that still owe a result */
    private function outstandingStatuses(): array
    {
        return [
            LabOrderStatus::Ordered->value,
            LabOrderStatus::Collected->value,
            LabOrderStatus::Processing->value,
        ];
    }

    /** How long this order has been on the bench, in hours. */
    public function waitedHours(LabOrder $order): int
    {
        return (int) $order->created_at->diffInHours(now());
    }

    public function isOverdue(LabOrder $order): bool
    {
        return ! $order->status->isTerminal() && $this->waitedHours($order) >= self::OLD_HOURS;
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->outstanding;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'outstanding']);
        $this->resetPage();
    }

    // ── Entering a result, over the list ─────────────────────────────────

    /** The order the dialog is open on. */
    #[Computed]
    public function working(): ?LabOrder
    {
        return $this->resultId === null ? null : LabOrder::with([
            'items', 'patient', 'orderedBy', 'visit', 'attachments.uploader',
        ])->find($this->resultId);
    }

    public function openResult(int $id): void
    {
        $this->authorizeView();

        $order = LabOrder::with('items')->findOrFail($id);

        $this->resultId = $order->id;
        $this->results = [];

        foreach ($order->items as $item) {
            $this->results[$item->id] = [
                'result_value' => $item->result_value,
                'result_flag' => $item->result_flag?->value,
                'result_notes' => $item->result_notes,
            ];
        }

        unset($this->working);
        $this->loadProvided($this->chargeTo());
        $this->formNonce++;
        $this->resetErrorBag();
        $this->showResult = true;
    }

    public function closeResult(): void
    {
        $this->reset(['showResult', 'resultId', 'results', 'files', 'provided']);
        $this->resetErrorBag();
    }

    /**
     * Save every value at once.
     *
     * The old page saved on blur, one field at a time, which meant a bench
     * with a dropped connection found out field by field. One button, one
     * round trip, one answer.
     */
    public function saveResults(LabService $service): void
    {
        $this->assertMayAttach();

        $order = $this->working;

        if ($order === null) {
            return;
        }

        $rules = $this->providedRules();
        foreach ($order->items as $item) {
            foreach (LabResultRequest::rulesFor() as $field => $rule) {
                $rules["results.{$item->id}.{$field}"] = $rule;
            }
        }
        $this->validate($rules, $this->providedMessages());

        $written = 0;

        foreach ($order->items as $item) {
            $draft = $this->results[$item->id] ?? null;

            if ($draft === null) {
                continue;
            }

            $service->recordResult($item, [
                'result_value' => ($draft['result_value'] ?? '') !== '' ? $draft['result_value'] : null,
                'result_flag' => ($draft['result_flag'] ?? '') !== '' ? $draft['result_flag'] : null,
                'result_notes' => ($draft['result_notes'] ?? '') !== '' ? $draft['result_notes'] : null,
            ], Auth::id());

            $written++;
        }

        // What the bench used goes onto the same order the tests were billed
        // to, so one piece of work is one set of charges.
        $charged = $this->chargeProvided();

        unset($this->working, $this->waiting);

        $this->dispatch('toast', type: 'success',
            message: $written.' '.\Illuminate\Support\Str::plural('result', $written).' saved'
                .($charged > 0 ? ', '.$charged.' '.\Illuminate\Support\Str::plural('line', $charged).' charged.' : '.'));
    }

    /** Save the results AND hand the order back, in one press. */
    public function completeOrder(LabService $service): void
    {
        $this->assertMayAttach();

        $order = $this->working;

        if ($order === null) {
            return;
        }

        $this->saveResults($service);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $service->transition($order->fresh(), LabOrderStatus::Completed, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->closeResult();
        unset($this->waiting);

        $this->dispatch('toast', type: 'success', message: 'Result reported back.');
    }

    public function advance(int $id, string $status, LabService $service): void
    {
        $this->assertMayAttach();

        $order = LabOrder::findOrFail($id);
        $to = LabOrderStatus::tryFrom($status);

        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown lab order status.', type: 'error');

            return;
        }

        try {
            $service->transition($order, $to, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->working, $this->waiting);
        $this->dispatch('toast', type: 'success', message: 'Moved to '.$to->label().'.');
    }

    /** @return array<string,string> */
    public function flags(): array
    {
        return \App\Enums\ResultFlag::options();
    }

    public function render()
    {
        $this->authorizeView();

        $query = LabOrder::with(['patient', 'visit'])->withCount(['items', 'attachments'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->outstanding, fn (Builder $q) => $q->whereIn('status', $this->outstandingStatuses()))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('patient_no', 'like', "%{$this->search}%")));

        /** @var Builder<LabOrder> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->latest('id'));

        return view('livewire.lab-orders.index', [
            'orders' => $sorted->paginate($this->perPage),
            'statuses' => LabOrderStatus::options(),
        ])->title('Lab orders');
    }
}
