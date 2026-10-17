<?php

namespace App\Livewire\Credits;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\InvoiceCredit;
use App\Models\User;
use App\Services\InvoiceCreditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Every credit note and refund, newest first (docs/refunds.md). The
 * approver's list: the ones waiting are a filter away, and each is approved
 * or rejected here or on its invoice — the same service either way.
 *
 * @property-read InvoiceCredit|null $peeked
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $kind = '';

    public bool $showReject = false;

    #[Locked]
    public ?int $rejectId = null;

    public string $rejectNote = '';

    public function mount(): void
    {
        $this->authorize('viewAny', InvoiceCredit::class);
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingKind(): void
    {
        $this->resetPage();
    }

    protected function peekModel(): string
    {
        return InvoiceCredit::class;
    }

    protected function peekRelations(): array
    {
        return ['invoice', 'patient', 'requestedBy:id,name', 'decidedBy:id,name', 'card'];
    }

    protected function authorizePeek(Model $record): void
    {
        $this->authorize('view', $record);
    }

    public function approve(int $id, InvoiceCreditService $service): void
    {
        $credit = InvoiceCredit::query()->findOrFail($id);
        $this->authorize('approve', $credit);

        try {
            /** @var User $user */
            $user = Auth::user();
            $credit = $service->approve($credit, $user);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        unset($this->peeked);
        $this->dispatch('toast', type: 'success', message: $credit->kind->label().' '.$credit->number.' approved.');
    }

    public function openReject(int $id): void
    {
        $credit = InvoiceCredit::query()->findOrFail($id);
        $this->authorize('approve', $credit);

        $this->resetErrorBag();
        $this->rejectId = $credit->id;
        $this->rejectNote = '';
        $this->showPeek = false;
        $this->showReject = true;
    }

    public function reject(InvoiceCreditService $service): void
    {
        $credit = InvoiceCredit::query()->findOrFail((int) $this->rejectId);
        $this->authorize('approve', $credit);
        $this->validate(['rejectNote' => ['required', 'string', 'min:3', 'max:500']], ['rejectNote.required' => 'Say why it is rejected.']);

        try {
            /** @var User $user */
            $user = Auth::user();
            $service->reject($credit, $this->rejectNote, $user);
        } catch (RuntimeException $e) {
            $this->addError('rejectNote', $e->getMessage());

            return;
        }

        $this->showReject = false;
        $this->dispatch('toast', type: 'success', message: 'Rejected.');
    }

    public function render()
    {
        $this->authorize('viewAny', InvoiceCredit::class);

        $credits = InvoiceCredit::query()->with(['invoice', 'patient', 'requestedBy:id,name'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
            ->matching($this->search)
            ->latest('requested_at')->latest('id')
            ->paginate($this->perPage);

        return view('livewire.credits.index', [
            'credits' => $credits,
            'statuses' => CreditStatus::options(),
            'kinds' => CreditKind::options(),
            'waiting' => InvoiceCredit::query()->where('status', CreditStatus::Pending->value)->count(),
        ])->title('Refunds and credit notes');
    }
}
