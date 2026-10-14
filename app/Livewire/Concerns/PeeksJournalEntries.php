<?php

namespace App\Livewire\Concerns;

use App\Exceptions\ClosedPeriodException;
use App\Models\JournalEntry;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * One journal entry over the list it was clicked in — its lines, where it came
 * from, and (for an entry a person wrote) the way to reverse it. Shared by
 * every finance page that lists entries, so an entry reads the same wherever
 * it is opened.
 *
 * Requires the host to render livewire.finance.partials.entry-peek.
 */
trait PeeksJournalEntries
{
    public bool $showEntry = false;

    #[Locked]
    public ?int $entryId = null;

    public string $reverseReason = '';

    public function peekEntry(int $id): void
    {
        abort_unless(Auth::user()?->can('finance.view'), 403);

        $this->entryId = JournalEntry::query()->findOrFail($id)->id;
        $this->reverseReason = '';
        $this->resetErrorBag('reverseReason');
        unset($this->peekedEntry);
        $this->showEntry = true;
    }

    #[Computed]
    public function peekedEntry(): ?JournalEntry
    {
        return $this->entryId === null ? null : JournalEntry::query()
            ->with(['lines.account', 'createdBy', 'reverses', 'reversedBy', 'source'])
            ->find($this->entryId);
    }

    public function reverseEntry(LedgerService $ledger): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $entry = JournalEntry::query()->findOrFail($this->entryId);
        $this->validate(['reverseReason' => ['required', 'string', 'max:200']], [], ['reverseReason' => 'reason']);

        try {
            $reversal = $ledger->reverse($entry, $this->reverseReason, Auth::id());
        } catch (\DomainException|ClosedPeriodException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->showEntry = false;
        $this->dispatch('toast', message: $entry->entry_no.' reversed by '.$reversal->entry_no.'.', type: 'success');
        $this->afterEntryReversed();
    }

    /** Hook: the host refreshes whatever it shows. */
    protected function afterEntryReversed(): void {}

    /**
     * Where a system entry came from, as a link a person can follow.
     *
     * @return array{label:string,url:?string}|null
     */
    public function entrySourceLink(JournalEntry $entry): ?array
    {
        $source = $entry->source;

        return match (true) {
            $source instanceof \App\Models\Invoice => ['label' => 'Invoice '.$source->invoice_no, 'url' => route('admin.invoices.show', $source)],
            $source instanceof \App\Models\Payment => ['label' => 'Payment on '.($source->invoice->invoice_no ?? 'an invoice'), 'url' => $source->invoice ? route('admin.invoices.show', $source->invoice) : null],
            $source instanceof \App\Models\StockMovement => ['label' => 'Stock ledger', 'url' => route('admin.stock.movements')],
            $source instanceof \App\Models\CardRecord => ['label' => 'Card records', 'url' => route('admin.card-records.index')],
            $source instanceof \App\Models\InsuranceTransaction => ['label' => 'Insurer float', 'url' => $source->provider ? route('admin.insurance-providers.show', $source->provider) : null],
            default => null,
        };
    }
}
