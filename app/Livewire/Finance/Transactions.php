<?php

namespace App\Livewire\Finance;

use App\Enums\JournalKind;
use App\Livewire\Concerns\KeepsBooks;
use App\Livewire\Concerns\PeeksJournalEntries;
use App\Livewire\Concerns\RecordsMoney;
use App\Livewire\Concerns\WithTable;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ReportRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The cashbook (docs/finance.md): every movement of money through the
 * hospital's books — patients paying, a card topped up, rent paid, cash
 * banked — one row per movement, with the book it went through and what it
 * was for.
 *
 * Filter to one book and it reads like that book's statement; the page
 * header then shows the book's balance. Money in, money out and transfers
 * are recorded from here.
 *
 * @property-read \Illuminate\Support\Collection<int, LedgerAccount> $books
 * @property-read string|null $bookBalance
 * @property-read \Illuminate\Support\Collection<int, LedgerAccount> $moneyBooks
 * @property-read array<string, array<int,string>> $moneyCategories
 * @property-read \App\Models\JournalEntry|null $peekedEntry
 */
#[Layout('layouts.admin')]
class Transactions extends Component
{
    use KeepsBooks, PeeksJournalEntries, RecordsMoney, WithTable;

    #[Url(as: 'book', history: true, except: '')]
    public string $filterBook = '';

    #[Url(as: 'kind', history: true, except: '')]
    public string $filterKind = '';

    #[Url(as: 'dir', history: true, except: '')]
    public string $filterDirection = '';   // in | out

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    #[Url(as: 'new', except: '')]
    public string $new = '';

    public function mount(): void
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        // "Money out" from elsewhere lands here with the dialog open.
        if (in_array($this->new, ['in', 'out', 'transfer'], true) && Auth::user()->can('finance.record')) {
            $this->openMoney($this->new);
        }
        $this->new = '';
    }

    protected function resetsPage(): array
    {
        return ['filterBook', 'filterKind', 'filterDirection', 'from', 'to'];
    }

    public function preset(string $key): void
    {
        [$this->from, $this->to] = ReportRange::forPreset($key);
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['filterBook', 'filterKind', 'filterDirection', 'from', 'to', 'search']);
        $this->resetPage();
    }

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function books()
    {
        return LedgerAccount::query()->books()->ordered()->get();
    }

    /** The chosen book's balance today, for the header. */
    #[Computed]
    public function bookBalance(): ?string
    {
        $book = $this->filterBook !== '' ? $this->books->firstWhere('id', (int) $this->filterBook) : null;

        return $book === null ? null : app(FinancialStatements::class)->balances(Carbon::now())[$book->id] ?? '0.00';
    }

    protected function afterMoneyRecorded(): void
    {
        unset($this->bookBalance);
    }

    protected function afterEntryReversed(): void
    {
        unset($this->bookBalance);
    }

    /** @return Builder<JournalLine> */
    private function query(): Builder
    {
        $bookIds = $this->books->pluck('id')->all();

        return JournalLine::query()
            ->with(['account', 'entry.lines.account'])
            ->whereIn('ledger_account_id', $this->filterBook !== '' ? [(int) $this->filterBook] : $bookIds)
            ->when($this->filterDirection === 'in', fn ($q) => $q->where('debit', '>', 0))
            ->when($this->filterDirection === 'out', fn ($q) => $q->where('credit', '>', 0))
            ->when($this->from !== '', fn ($q) => $q->where('date', '>=', Carbon::parse($this->from)->toDateString()))
            ->when($this->to !== '', fn ($q) => $q->where('date', '<=', Carbon::parse($this->to)->toDateString()))
            ->when($this->filterKind !== '' && JournalKind::tryFrom($this->filterKind), fn ($q) => $q->whereHas('entry', fn ($e) => $e->where('kind', $this->filterKind)))
            ->when($this->search !== '', fn ($q) => $q->whereHas('entry', fn ($e) => $e
                ->where('memo', 'like', "%{$this->search}%")
                ->orWhere('party', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhere('entry_no', 'like', "%{$this->search}%")))
            ->orderByDesc('date')->orderByDesc('journal_entry_id')->orderByDesc('id');
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        $rows = $this->query()->paginate($this->perPage);

        // In and out on this page, for the totals row.
        $in = $rows->getCollection()->reduce(fn ($c, $l) => bcadd($c, (string) $l->debit, 2), '0.00');
        $out = $rows->getCollection()->reduce(fn ($c, $l) => bcadd($c, (string) $l->credit, 2), '0.00');

        return view('livewire.finance.transactions', [
            'rows' => $rows,
            'pageIn' => $in,
            'pageOut' => $out,
            'kinds' => JournalKind::options(),
            'presets' => ReportRange::presets(),
        ])->title('Transactions');
    }
}
