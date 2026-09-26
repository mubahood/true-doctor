<?php

namespace App\Livewire\RadiologyOrders;

use App\Enums\RadiologyOrderStatus;
use App\Http\Requests\RadiologyReportRequest;
use App\Livewire\Concerns\ChargesWorkDone;
use App\Livewire\Concerns\CollectsAttachments;
use App\Livewire\Concerns\WithTable;
use App\Models\Contracts\HoldsAttachments;
use App\Models\RadiologyOrder;
use App\Services\RadiologyService;
use App\Support\RadiologyBench;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The reporting list: what has been imaged, and what still needs reading.
 *
 * The same rebuild as the lab bench, for the same reasons — this was a flat
 * list behind an eye icon, with nothing saying how long a study had gone
 * unread and NOWHERE TO PUT THE IMAGES. A radiology module that cannot hold a
 * film is a radiology module in name only.
 *
 * Findings and impression are written in a dialog over the list, with the
 * images beside them (App\Livewire\Concerns\CollectsAttachments), and signing
 * off is the same press that saves them.
 *
 * @property-read array{ordered:int,scheduled:int,performed:int,reported:int,oldest:int|null} $waiting
 * @property-read RadiologyOrder|null $working
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use ChargesWorkDone, CollectsAttachments, WithTable;

    /** A study unread for this long is the thing the list exists to show. */
    public const OLD_HOURS = \App\Support\RadiologyBench::OLD_HOURS;

    #[Url(history: true, except: '')]
    public string $status = '';

    /** Only what still needs reading. */
    #[Url(history: true, except: false)]
    public bool $outstanding = false;

    // ── The report dialog ────────────────────────────────────────────────
    public bool $showReport = false;

    public ?int $reportId = null;

    public ?string $findings = null;

    public ?string $impression = null;

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
        abort_unless(Auth::user()?->can('radiology.view') === true, 403);
    }

    protected function assertMayAttach(): void
    {
        abort_unless(Auth::user()?->can('radiology.report') === true, 403);
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
     * It has existed since the work was ordered — RadiologyOrderService places one
     * of type Imaging with this record as its `subject`, and bills the tests
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
            \App\Enums\OrderType::Imaging,
            'Imaging',
            \Illuminate\Support\Facades\Auth::id(),
        );
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        $this->providedPicked($name, $id);
    }

    // ── What is still to be read ─────────────────────────────────────────

    /**
     * @return array{ordered:int,scheduled:int,performed:int,reported:int,oldest:int|null}
     */
    #[Computed]
    public function waiting(): array
    {
        return RadiologyBench::tally();
    }

    public function waitedHours(RadiologyOrder $order): int
    {
        return RadiologyBench::waitedHours($order);
    }

    public function isOverdue(RadiologyOrder $order): bool
    {
        return RadiologyBench::isOverdue($order);
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

    // ── Reading it, over the list ────────────────────────────────────────

    #[Computed]
    public function working(): ?RadiologyOrder
    {
        return $this->reportId === null ? null : RadiologyOrder::with([
            'items', 'patient', 'orderedBy', 'reportedBy', 'visit', 'attachments.uploader',
        ])->find($this->reportId);
    }

    public function openReport(int $id): void
    {
        $this->authorizeView();

        $order = RadiologyOrder::findOrFail($id);

        $this->reportId = $order->id;
        $this->findings = $order->findings;
        $this->impression = $order->impression;

        unset($this->working);
        $this->loadProvided($this->chargeTo());
        $this->formNonce++;
        $this->resetErrorBag();
        $this->showReport = true;
    }

    public function closeReport(): void
    {
        $this->reset(['showReport', 'reportId', 'findings', 'impression', 'files', 'provided']);
        $this->resetErrorBag();
    }

    public function saveReport(RadiologyService $service): void
    {
        $this->assertMayAttach();

        $order = $this->working;

        if ($order === null) {
            return;
        }

        $data = $this->validate(
            array_merge(RadiologyReportRequest::rulesFor(), $this->providedRules()),
            $this->providedMessages(),
        );

        $service->recordReport($order, $data['findings'] ?? null, $data['impression'] ?? null, Auth::id());

        // Contrast, film, a radiographer's time — charged onto the same order
        // the study was billed to.
        $charged = $this->chargeProvided();

        unset($this->working, $this->waiting);

        $this->dispatch('toast', type: 'success', message: 'Report saved'
            .($charged > 0 ? ', '.$charged.' '.\Illuminate\Support\Str::plural('line', $charged).' charged.' : '.'));
    }

    /** Write the report AND sign it off, in one press. */
    public function signOff(RadiologyService $service): void
    {
        $this->assertMayAttach();

        $order = $this->working;

        if ($order === null) {
            return;
        }

        $this->saveReport($service);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $service->transition($order->fresh(), RadiologyOrderStatus::Reported, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->closeReport();
        unset($this->waiting);

        $this->dispatch('toast', type: 'success', message: 'Reported and signed off.');
    }

    public function advance(int $id, string $status, RadiologyService $service): void
    {
        $this->assertMayAttach();

        $order = RadiologyOrder::findOrFail($id);
        $to = RadiologyOrderStatus::tryFrom($status);

        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown radiology order status.', type: 'error');

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

    public function render()
    {
        $this->authorizeView();

        $query = RadiologyOrder::with(['patient', 'visit'])->withCount(['items', 'attachments'])
            ->tap(fn (Builder $q) => RadiologyBench::filter($q, $this->status, $this->outstanding, $this->search));

        /** @var Builder<RadiologyOrder> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->latest('id'));

        return view('livewire.radiology-orders.index', [
            'orders' => $sorted->paginate($this->perPage),
            'statuses' => RadiologyOrderStatus::options(),
        ])->title('Radiology orders');
    }
}
