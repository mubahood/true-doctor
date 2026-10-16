<?php

namespace App\Livewire\Finance;

use App\Enums\JournalKind;
use App\Exceptions\ClosedPeriodException;
use App\Http\Requests\CashEntryRequest;
use App\Livewire\Concerns\KeepsBooks;
use App\Livewire\Concerns\PeeksJournalEntries;
use App\Livewire\Concerns\WithTable;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Support\HospitalSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The general journal (docs/finance.md): every entry, from every source, in
 * order. This is the accountant's page — where a journal of their own is
 * written (depreciation, an accrual, clearing suspense), where the balances a
 * hospital started with are entered, and where a mistake is reversed.
 *
 * @property-read array<string, array<int,string>> $accountOptions
 * @property-read array{debit:string,credit:string,difference:string} $journalTotals
 * @property-read \Illuminate\Support\Collection<int, LedgerAccount> $openingAccounts
 * @property-read bool $hasOpening
 * @property-read \App\Models\JournalEntry|null $peekedEntry
 */
#[Layout('layouts.admin')]
class Journal extends Component
{
    use KeepsBooks, PeeksJournalEntries, WithTable;

    #[Url(as: 'kind', history: true, except: '')]
    public string $filterKind = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    // ── A journal of one's own ───────────────────────────────────────────
    public bool $showJournal = false;

    public ?string $jDate = null;

    public string $jMemo = '';

    public ?string $jReference = null;

    /** @var list<array{account_id:?string,debit:?string,credit:?string,memo:?string}> */
    public array $jLines = [];

    // ── Opening balances ─────────────────────────────────────────────────
    public bool $showOpening = false;

    public ?string $oDate = null;

    /** @var array<int|string, ?string> account id => amount */
    public array $oBalances = [];

    public function mount(): void
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();
    }

    protected function resetsPage(): array
    {
        return ['filterKind', 'from', 'to'];
    }

    /** @return array<string, array<int,string>> */
    #[Computed]
    public function accountOptions(): array
    {
        $groups = [];
        foreach (LedgerAccount::query()->active()->ordered()->get() as $a) {
            $groups[$a->type->plural()][$a->id] = $a->label();
        }

        return $groups;
    }

    public function newJournal(): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $this->resetErrorBag();
        $this->jDate = Carbon::today()->toDateString();
        $this->jMemo = '';
        $this->jReference = null;
        $this->jLines = array_fill(0, 2, ['account_id' => null, 'debit' => null, 'credit' => null, 'memo' => null]);
        $this->showJournal = true;
    }

    public function addLine(): void
    {
        if (count($this->jLines) < 40) {
            $this->jLines[] = ['account_id' => null, 'debit' => null, 'credit' => null, 'memo' => null];
        }
    }

    public function removeLine(int $i): void
    {
        if (count($this->jLines) > 2) {
            unset($this->jLines[$i]);
            $this->jLines = array_values($this->jLines);
        }
    }

    /** @return array{debit:string,credit:string,difference:string} */
    #[Computed]
    public function journalTotals(): array
    {
        $d = $c = '0.00';
        foreach ($this->jLines as $line) {
            $d = bcadd($d, is_numeric($line['debit'] ?? null) ? HospitalSettings::decimal((string) $line['debit'], 2) : '0', 2);
            $c = bcadd($c, is_numeric($line['credit'] ?? null) ? HospitalSettings::decimal((string) $line['credit'], 2) : '0', 2);
        }

        return ['debit' => $d, 'credit' => $c, 'difference' => bcsub($d, $c, 2)];
    }

    public function saveJournal(CashbookService $cashbook): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $rules = [];
        foreach (CashEntryRequest::journalRules() as $field => $r) {
            $rules[match (true) {
                $field === 'date' => 'jDate',
                $field === 'memo' => 'jMemo',
                $field === 'reference' => 'jReference',
                default => 'j'.ucfirst($field),
            }] = $r;
        }
        $data = $this->validate($rules, [], ['jDate' => 'date', 'jMemo' => 'description', 'jLines.*.account_id' => 'account', 'jLines.*.debit' => 'debit', 'jLines.*.credit' => 'credit']);

        try {
            $entry = $cashbook->journal(['date' => $data['jDate'], 'memo' => $data['jMemo'], 'reference' => $data['jReference'] ?? null], $data['jLines'], Auth::id());
        } catch (\DomainException|ClosedPeriodException $e) {
            $this->addError('jLines', $e->getMessage());

            return;
        }

        $this->showJournal = false;
        $this->dispatch('toast', message: 'Journal '.$entry->entry_no.' posted.', type: 'success');
    }

    // ── Opening balances ─────────────────────────────────────────────────

    /** The accounts a hospital starts with a balance in: what it has and what it owes. */
    #[Computed]
    public function openingAccounts()
    {
        return app(CashbookService::class)->openingAccounts();
    }

    #[Computed]
    public function hasOpening(): bool
    {
        return app(CashbookService::class)->hasOpening();
    }

    public function newOpening(): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $this->resetErrorBag();
        $this->oDate = Carbon::today()->startOfMonth()->toDateString();
        $this->oBalances = [];
        $this->showOpening = true;
    }

    public function saveOpening(CashbookService $cashbook): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $this->validate([
            'oDate' => ['required', 'date'],
            'oBalances' => ['array'],
            'oBalances.*' => ['nullable', 'numeric', 'min:-9999999999.99', 'max:9999999999.99'],
        ], [], ['oDate' => 'date', 'oBalances.*' => 'amount']);

        try {
            $entry = $cashbook->openingBalances((string) $this->oDate, $this->oBalances, Auth::id());
        } catch (\DomainException|ClosedPeriodException $e) {
            $this->addError('oBalances', $e->getMessage());

            return;
        }

        $this->showOpening = false;
        unset($this->hasOpening);
        $this->dispatch('toast', message: 'Opening balances posted as '.$entry->entry_no.'.', type: 'success');
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        $rows = JournalEntry::query()
            ->withCount('lines')
            ->when($this->filterKind !== '' && JournalKind::tryFrom($this->filterKind), fn ($q) => $q->where('kind', $this->filterKind))
            ->when($this->from !== '', fn ($q) => $q->where('date', '>=', Carbon::parse($this->from)->toDateString()))
            ->when($this->to !== '', fn ($q) => $q->where('date', '<=', Carbon::parse($this->to)->toDateString()))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('memo', 'like', "%{$this->search}%")
                ->orWhere('entry_no', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhere('party', 'like', "%{$this->search}%")))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.finance.journal', [
            'rows' => $rows,
            'kinds' => JournalKind::options(),
        ])->title('Journal');
    }
}
