<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Invoices ledger — status filter + free-text search (invoice no. or patient),
 * newest first. Thin, tenant-scoped, read-only list.
 *
 * A row says what an invoice COMES TO. What gets asked of it is what it is
 * MADE OF and what has been paid against it, so both open over the list; the
 * invoice's own page stays where the money is actually taken.
 *
 * @property-read Invoice|null $peeked
 * @property-read Collection<int,InvoiceItem> $peekedItems
 * @property-read Collection<int,Payment> $peekedPayments
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    #[Url(history: true)]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Invoice::class;
    }

    protected function peekRelations(): array
    {
        return ['patient', 'visit'];
    }

    protected function peekCaches(): array
    {
        return ['peekedItems', 'peekedPayments'];
    }

    /**
     * What the invoice is made of.
     *
     * Capped, because a long stay can run to dozens of lines and the whole
     * list is the invoice's own page. The dialog says how many were left off.
     *
     * @return Collection<int,InvoiceItem>
     */
    #[Computed]
    public function peekedItems(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : InvoiceItem::where('invoice_id', $this->peekId)->orderBy('id')->limit(8)->get();
    }

    /** @return Collection<int,Payment> */
    #[Computed]
    public function peekedPayments(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : Payment::with('receivedBy')->where('invoice_id', $this->peekId)->latest('id')->limit(5)->get();
    }

    /** How many lines the dialog did not show — silence here reads as "that's all". */
    public function itemsNotShown(): int
    {
        return $this->peekId === null
            ? 0
            : max(0, InvoiceItem::where('invoice_id', $this->peekId)->count() - $this->peekedItems->count());
    }

    public function render()
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::with(['patient'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', function (Builder $query) {
                $term = $this->search;
                $query->where(function (Builder $qq) use ($term) {
                    $qq->where('invoice_no', 'like', "%{$term}%")
                        ->orWhereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('patient_no', 'like', "%{$term}%"));
                });
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::options(),
        ])->title('Invoices');
    }
}
