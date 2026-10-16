<?php

namespace App\Livewire\Concerns;

use App\Exceptions\ClosedPeriodException;
use App\Http\Requests\CashEntryRequest;
use App\Models\LedgerAccount;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Money in, money out and transfers between books — one dialog, shared by the
 * finance overview and the transactions page so the two can never disagree
 * about what a receipt needs (docs/finance.md).
 *
 * The dialog asks what a person at a desk can answer — which book, what it
 * was for, how much, to or from whom — and CashbookService makes the double
 * entry. The category lists lead with what the kind usually is (income for
 * money in, expenses for money out) and keep the rest below for the
 * less-common cases: an insurer paying what it owed, a supplier being paid,
 * equipment bought, capital put in.
 *
 * Requires the host to render livewire.finance.partials.money-form.
 */
trait RecordsMoney
{
    public bool $showMoney = false;

    public string $moneyKind = 'in';   // in | out | transfer

    public ?string $moneyDate = null;

    public ?string $moneyBook = null;

    public ?string $moneyAccount = null;

    public ?string $moneyAmount = null;

    public ?string $moneyMemo = null;

    public ?string $moneyParty = null;

    public ?string $moneyReference = null;

    public ?string $moneyFromBook = null;

    public ?string $moneyToBook = null;

    public function openMoney(string $kind = 'in'): void
    {
        abort_unless(Auth::user()?->can('finance.record'), 403);
        app(ChartOfAccounts::class)->ensure();

        $this->reset(['moneyBook', 'moneyAccount', 'moneyAmount', 'moneyMemo', 'moneyParty', 'moneyReference', 'moneyFromBook', 'moneyToBook']);
        $this->resetErrorBag();

        $this->moneyKind = in_array($kind, ['in', 'out', 'transfer'], true) ? $kind : 'in';
        $this->moneyDate = Carbon::today()->toDateString();

        $first = (string) ($this->moneyBooks->first()->id ?? '');
        $this->moneyBook = $first;
        $this->moneyFromBook = $first;
        $this->moneyToBook = (string) ($this->moneyBooks->skip(1)->first()->id ?? '');

        $this->showMoney = true;
    }

    public function saveMoney(CashbookService $cashbook): void
    {
        abort_unless(Auth::user()?->can('finance.record'), 403);

        $kind = $this->moneyKind === 'transfer' ? 'transfer' : 'cash';
        $map = [
            'date' => 'moneyDate', 'amount' => 'moneyAmount', 'reference' => 'moneyReference', 'memo' => 'moneyMemo',
            'party' => 'moneyParty', 'book_id' => 'moneyBook', 'account_id' => 'moneyAccount',
            'from_book_id' => 'moneyFromBook', 'to_book_id' => 'moneyToBook',
        ];

        $rules = [];
        foreach (CashEntryRequest::rulesFor($kind) as $field => $fieldRules) {
            $rules[$map[$field]] = array_map(
                fn ($r) => is_string($r) ? str_replace('different:from_book_id', 'different:moneyFromBook', $r) : $r,
                $fieldRules,
            );
        }

        $data = $this->validate($rules, [], [
            'moneyBook' => 'book', 'moneyAccount' => 'category', 'moneyAmount' => 'amount', 'moneyMemo' => 'description',
            'moneyDate' => 'date', 'moneyFromBook' => 'from', 'moneyToBook' => 'to',
        ]);

        try {
            $entry = match ($this->moneyKind) {
                'transfer' => $cashbook->transfer([
                    'date' => $data['moneyDate'], 'from_book_id' => (int) $data['moneyFromBook'], 'to_book_id' => (int) $data['moneyToBook'],
                    'amount' => (string) $data['moneyAmount'], 'memo' => $data['moneyMemo'] ?? null, 'reference' => $data['moneyReference'] ?? null,
                ], Auth::id()),
                'out' => $cashbook->moneyOut($this->moneyPayload($data), Auth::id()),
                default => $cashbook->moneyIn($this->moneyPayload($data), Auth::id()),
            };
        } catch (\DomainException|ClosedPeriodException $e) {
            $this->addError($this->moneyKind === 'transfer' ? 'moneyToBook' : 'moneyAccount', $e->getMessage());

            return;
        }

        $this->showMoney = false;
        $this->dispatch('toast', message: match ($this->moneyKind) {
            'transfer' => 'Transfer recorded',
            'out' => 'Money out recorded',
            default => 'Money in recorded',
        }.' — '.$entry->entry_no.'.', type: 'success');

        $this->afterMoneyRecorded();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function moneyPayload(array $data): array
    {
        return [
            'date' => $data['moneyDate'], 'book_id' => (int) $data['moneyBook'], 'account_id' => (int) $data['moneyAccount'],
            'amount' => (string) $data['moneyAmount'], 'memo' => $data['moneyMemo'],
            'party' => $data['moneyParty'] ?? null, 'reference' => $data['moneyReference'] ?? null,
        ];
    }

    /** Hook: the host refreshes whatever it shows. */
    protected function afterMoneyRecorded(): void {}

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function moneyBooks(): \Illuminate\Support\Collection
    {
        return LedgerAccount::query()->books()->active()->ordered()->get();
    }

    /**
     * What money can be for, grouped, the usual kind first.
     *
     * @return array<string, array<int,string>> group label => [id => "code · name"]
     */
    #[Computed]
    public function moneyCategories(): array
    {
        return app(CashbookService::class)->categories($this->moneyKind);
    }

    public function updatedMoneyKind(): void
    {
        unset($this->moneyCategories);
        $this->moneyAccount = null;
    }
}
