<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\JournalKind;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\HospitalSettings;

/**
 * Money that did not come from a patient's bill, written in by hand
 * (docs/finance.md): rent paid, a grant received, salaries, a float moved
 * from the cash drawer to the bank, and the accountant's own journals.
 *
 * The forms ask the questions a person at a desk can answer — which book,
 * which category, how much, to or from whom — and this turns the answer into
 * the double entry. Nobody filling in "Money out: Electricity, 450,000, from
 * the bank" has to know that it is a debit to 6110 and a credit to 1020.
 */
class CashbookService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Money in. The category is what it was for — an income account, usually,
     * but it can be money collected against what someone owed (an insurer
     * paying its receivable) or capital the owner put in.
     *
     * @param  array{date:string,book_id:int,account_id:int,amount:string,memo:string,party?:?string,reference?:?string}  $data
     */
    public function moneyIn(array $data, ?int $by = null): JournalEntry
    {
        $book = $this->book((int) $data['book_id']);
        $category = $this->category((int) $data['account_id']);
        $amount = $this->amount($data['amount']);

        return $this->ledger->post([
            'date' => $data['date'],
            'kind' => JournalKind::Income,
            'memo' => $data['memo'],
            'party' => $data['party'] ?? null,
            'reference' => $data['reference'] ?? null,
            'by' => $by,
        ], [
            ['account' => $book, 'debit' => $amount],
            ['account' => $category, 'credit' => $amount],
        ]);
    }

    /**
     * Money out. Usually an expense; it can also be paying a supplier what a
     * delivery was owed against, buying equipment, or the owner drawing.
     *
     * @param  array{date:string,book_id:int,account_id:int,amount:string,memo:string,party?:?string,reference?:?string}  $data
     */
    public function moneyOut(array $data, ?int $by = null): JournalEntry
    {
        $book = $this->book((int) $data['book_id']);
        $category = $this->category((int) $data['account_id']);
        $amount = $this->amount($data['amount']);

        return $this->ledger->post([
            'date' => $data['date'],
            'kind' => JournalKind::Expense,
            'memo' => $data['memo'],
            'party' => $data['party'] ?? null,
            'reference' => $data['reference'] ?? null,
            'by' => $by,
        ], [
            ['account' => $category, 'debit' => $amount],
            ['account' => $book, 'credit' => $amount],
        ]);
    }

    /**
     * Money moved between two of the hospital's own books — the day's cash
     * banked, mobile money withdrawn. Nothing was earned or spent.
     *
     * @param  array{date:string,from_book_id:int,to_book_id:int,amount:string,memo?:?string,reference?:?string}  $data
     */
    public function transfer(array $data, ?int $by = null): JournalEntry
    {
        $from = $this->book((int) $data['from_book_id']);
        $to = $this->book((int) $data['to_book_id']);

        if ($from->is($to)) {
            throw new \DomainException('Choose two different books — money moved to where it already is has not moved.');
        }

        $amount = $this->amount($data['amount']);

        return $this->ledger->post([
            'date' => $data['date'],
            'kind' => JournalKind::Transfer,
            'memo' => trim((string) ($data['memo'] ?? '')) ?: 'Transfer from '.$from->name.' to '.$to->name,
            'reference' => $data['reference'] ?? null,
            'by' => $by,
        ], [
            ['account' => $to, 'debit' => $amount],
            ['account' => $from, 'credit' => $amount],
        ]);
    }

    /**
     * An accountant's journal: any accounts, any number of lines, as long as
     * they balance. Depreciation, accruals, reclassifying something posted to
     * the wrong place, clearing suspense.
     *
     * @param  array{date:string,memo:string,reference?:?string}  $header
     * @param  list<array{account_id:int|string|null,debit?:string|null,credit?:string|null,memo?:?string}>  $lines
     */
    public function journal(array $header, array $lines, ?int $by = null): JournalEntry
    {
        $rows = [];
        foreach ($lines as $line) {
            if (blank($line['account_id'] ?? null) && blank($line['debit'] ?? null) && blank($line['credit'] ?? null)) {
                continue; // an empty row left at the bottom of the form
            }
            if (blank($line['account_id'] ?? null)) {
                throw new \DomainException('Every line with an amount needs an account.');
            }

            $rows[] = [
                'account' => (int) $line['account_id'],
                'debit' => blank($line['debit'] ?? null) ? '0' : (string) $line['debit'],
                'credit' => blank($line['credit'] ?? null) ? '0' : (string) $line['credit'],
                'memo' => $line['memo'] ?? null,
            ];
        }

        return $this->ledger->post([
            'date' => $header['date'],
            'kind' => JournalKind::Journal,
            'memo' => $header['memo'],
            'reference' => $header['reference'] ?? null,
            'by' => $by,
        ], $rows);
    }

    /**
     * What the hospital had and owed on the day it started keeping its books
     * here: the cash in each book, what the bank held, what was owed to
     * suppliers. Each figure goes to its account, and the difference — what
     * the hospital was worth — to opening balance equity, so the entry always
     * balances without anybody having to work it out.
     *
     * @param  array<int|string, string|int|float|null>  $balances  account id => amount (as the account normally reads)
     */
    public function openingBalances(string $date, array $balances, ?int $by = null): JournalEntry
    {
        $lines = [];
        $net = '0.00';   // debits less credits so far

        foreach ($balances as $accountId => $amount) {
            if (blank($amount)) {
                continue;
            }

            $amount = HospitalSettings::decimal((string) $amount, 2);
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $account = LedgerAccount::query()->find((int) $accountId);
            if (! $account instanceof LedgerAccount) {
                throw new \DomainException('One of those accounts is not in this hospital’s chart.');
            }
            if ($account->type->isTemporary()) {
                throw new \DomainException("“{$account->name}” is income or expense — it starts at nothing; opening balances are for what the hospital has and owes.");
            }

            // A positive figure is the account's normal side; a negative one,
            // the other (an overdrawn bank account).
            $debitSide = $account->type->debitNormal() === (bccomp($amount, '0', 2) > 0);
            $abs = ltrim($amount, '-');

            $lines[] = $debitSide ? ['account' => $account, 'debit' => $abs] : ['account' => $account, 'credit' => $abs];
            $net = $debitSide ? bcadd($net, $abs, 2) : bcsub($net, $abs, 2);
        }

        if ($lines === []) {
            throw new \DomainException('Enter at least one opening balance.');
        }

        if (bccomp($net, '0', 2) !== 0) {
            $equity = app(ChartOfAccounts::class)->key('opening_equity');
            $lines[] = bccomp($net, '0', 2) > 0
                ? ['account' => $equity, 'credit' => $net]
                : ['account' => $equity, 'debit' => bcmul($net, '-1', 2)];
        }

        return $this->ledger->post([
            'date' => $date,
            'kind' => JournalKind::Opening,
            'memo' => 'Opening balances',
            'by' => $by,
        ], $lines);
    }

    /**
     * What money can be for, grouped, the usual kind first — the category
     * picker of the money-in / money-out form.
     *
     * @return array<string, array<int,string>> group label => [id => "code · name"]
     */
    public function categories(string $kind): array
    {
        $accounts = LedgerAccount::query()->active()->where('is_book', false)
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['inventory', 'discounts', 'opening_equity']))
            ->ordered()->get();

        $order = $kind === 'out'
            ? [AccountType::Expense, AccountType::Liability, AccountType::Asset, AccountType::Equity, AccountType::Income]
            : [AccountType::Income, AccountType::Asset, AccountType::Liability, AccountType::Equity, AccountType::Expense];

        $heading = fn (AccountType $t) => match ([$kind, $t]) {
            ['in', AccountType::Income] => 'Income',
            ['in', AccountType::Asset] => 'Money owed to you, collected',
            ['in', AccountType::Liability] => 'Loans and deposits received',
            ['in', AccountType::Equity] => 'Capital put in',
            ['out', AccountType::Expense] => 'Expenses',
            ['out', AccountType::Liability] => 'Paying what you owe',
            ['out', AccountType::Asset] => 'Buying equipment and other assets',
            ['out', AccountType::Equity] => 'Owner\'s drawings',
            default => $t->plural().' (unusual here)',
        };

        $groups = [];
        foreach ($order as $type) {
            $rows = $accounts->where('type', $type);
            if ($rows->isNotEmpty()) {
                $groups[$heading($type)] = $rows->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->label()])->all();
            }
        }

        return $groups;
    }

    /** The accounts a hospital starts with a balance in: what it has and what it owes. */
    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    public function openingAccounts()
    {
        return LedgerAccount::query()->active()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
            // Not the ones the system keeps for itself: stock is valued by the
            // stock ledger, and card and insurer balances by their own ledgers.
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['opening_equity', 'retained_earnings', 'inventory', 'card_deposits', 'insurer_floats']))
            ->ordered()->get();
    }

    public function hasOpening(): bool
    {
        return JournalEntry::query()->where('kind', JournalKind::Opening->value)->whereNull('reversed_by_id')->exists();
    }

    private function book(int $id): LedgerAccount
    {
        $book = LedgerAccount::query()->find($id);

        if (! $book instanceof LedgerAccount || ! $book->is_book) {
            throw new \DomainException('Choose one of your money books — cash, bank or mobile money.');
        }
        if (! $book->is_active) {
            throw new \DomainException("“{$book->name}” is switched off.");
        }

        return $book;
    }

    private function category(int $id): LedgerAccount
    {
        $account = LedgerAccount::query()->find($id);

        if (! $account instanceof LedgerAccount) {
            throw new \DomainException('Choose what this was for.');
        }
        if ($account->is_book) {
            throw new \DomainException('That is one of your money books — to move money between books, record a transfer.');
        }
        if ($account->type === AccountType::Asset && $account->system_key === 'inventory') {
            throw new \DomainException('Stock is valued by the stock ledger — receive it in Pharmacy so the shelf and the books agree.');
        }

        return $account;
    }

    private function amount(string|int|float $amount): string
    {
        $value = HospitalSettings::decimal((string) $amount, 2);

        if (bccomp($value, '0', 2) <= 0) {
            throw new \DomainException('The amount must be more than nothing.');
        }

        return $value;
    }
}
