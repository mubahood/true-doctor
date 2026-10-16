<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The statements, read straight off the journal (docs/finance.md).
 *
 * Nothing is stored: every figure is a sum of journal lines over a range of
 * dates, so a statement can never disagree with the entries under it, and an
 * entry backdated into last month shows in last month's figures the moment it
 * is posted.
 *
 * There are no closing entries. Income and expenses belong to a year; when a
 * statement needs "everything the hospital ever earned before this year" it
 * sums it and shows it as retained earnings. The year a date belongs to is
 * the hospital's financial year containing it, or the calendar year where it
 * has none.
 *
 * Every figure is a scale-2 string.
 */
class FinancialStatements
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    // ── By name ──────────────────────────────────────────────────────────

    /**
     * The period a statement is compared against: `previous` is the period of
     * the same length just before, `year` the same dates a year earlier.
     *
     * @return array{0:?Carbon,1:?Carbon}
     */
    public function comparisonRange(string $vs, Carbon $from, Carbon $to): array
    {
        return match ($vs) {
            'previous' => [$from->copy()->subDays($from->diffInDays($to) + 1), $from->copy()->subDay()],
            'year' => [$from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow()],
            default => [null, null],
        };
    }

    /**
     * One of the four statements (income, balance, trial, cashflow) for a
     * period — the balance sheet and trial balance are as at `$to`.
     *
     * @return array<string,mixed>
     */
    public function report(string $report, Carbon $from, Carbon $to, string $vs = 'none'): array
    {
        return match ($report) {
            'balance' => $this->balanceSheet($to),
            'trial' => $this->trialBalance($to),
            'cashflow' => $this->cashFlow($from, $to),
            default => $this->incomeStatement($from, $to, ...$this->comparisonRange($vs, $from, $to)),
        };
    }

    // ── Trial balance ────────────────────────────────────────────────────

    /**
     * Every account's balance on a date, in the debit or credit column it
     * falls in. Income and expenses are this year's; what earlier years made
     * sits in retained earnings. The two columns always agree — if they do
     * not, something wrote to the journal without going through LedgerService.
     *
     * @return array{as_of:string,year_start:string,rows:list<array{account:LedgerAccount,debit:string,credit:string}>,debit:string,credit:string,balanced:bool}
     */
    public function trialBalance(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->endOfDay();
        $yearStart = $this->yearStart($asOf);

        $permanent = $this->sums(null, $asOf);
        $thisYear = $this->sums($yearStart, $asOf);
        $earlier = $this->profit(null, $yearStart->copy()->subDay());

        $rows = [];
        $debitTotal = '0.00';
        $creditTotal = '0.00';
        $retained = $this->retainedAccount();

        foreach ($this->accounts() as $account) {
            $sum = $account->type->isTemporary() ? ($thisYear[$account->id] ?? null) : ($permanent[$account->id] ?? null);
            $net = bcsub($sum['debit'] ?? '0.00', $sum['credit'] ?? '0.00', 2);   // + is a debit balance

            if ($retained !== null && $account->is($retained)) {
                $net = bcsub($net, $earlier, 2);   // earlier profit is a credit
            }

            if (bccomp($net, '0', 2) === 0) {
                continue;
            }

            $debit = bccomp($net, '0', 2) > 0 ? $net : '0.00';
            $credit = bccomp($net, '0', 2) < 0 ? bcmul($net, '-1', 2) : '0.00';

            $rows[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];
            $debitTotal = bcadd($debitTotal, $debit, 2);
            $creditTotal = bcadd($creditTotal, $credit, 2);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'year_start' => $yearStart->toDateString(),
            'rows' => $rows,
            'debit' => $debitTotal,
            'credit' => $creditTotal,
            'balanced' => bccomp($debitTotal, $creditTotal, 2) === 0,
        ];
    }

    // ── Income statement ─────────────────────────────────────────────────

    /**
     * Profit and loss for a range, laid out the way a clinic owner reads it:
     * what the work earned, less what was given away in discounts; less what
     * the drugs sold had cost; less what it cost to run the place; plus
     * anything earned on the side.
     *
     * With a comparison range, every row carries the figure for that range too.
     *
     * @return array<string,mixed>
     */
    public function incomeStatement(Carbon $from, Carbon $to, ?Carbon $compareFrom = null, ?Carbon $compareTo = null): array
    {
        $now = $this->sums($from->copy()->startOfDay(), $to->copy()->endOfDay());
        $then = $compareFrom && $compareTo ? $this->sums($compareFrom->copy()->startOfDay(), $compareTo->copy()->endOfDay()) : null;

        $section = function (callable $belongs) use ($now, $then): array {
            $rows = [];
            $total = '0.00';
            $compare = '0.00';

            foreach ($this->accounts() as $account) {
                if (! $belongs($account)) {
                    continue;
                }

                $amount = $this->signed($account, $now[$account->id] ?? null);
                $prior = $then !== null ? $this->signed($account, $then[$account->id] ?? null) : null;

                if (bccomp($amount, '0', 2) === 0 && ($prior === null || bccomp($prior, '0', 2) === 0)) {
                    continue;
                }

                $rows[] = ['account' => $account, 'amount' => $amount, 'compare' => $prior];
                $total = bcadd($total, $amount, 2);
                $compare = bcadd($compare, $prior ?? '0.00', 2);
            }

            return ['rows' => $rows, 'total' => $total, 'compare' => $then !== null ? $compare : null];
        };

        $revenue = $section(fn (LedgerAccount $a) => $a->type === AccountType::Income && ! in_array($a->subtype, ['other_income', 'contra_income'], true));
        $discounts = $section(fn (LedgerAccount $a) => $a->type === AccountType::Income && $a->subtype === 'contra_income');
        $cost = $section(fn (LedgerAccount $a) => $a->type === AccountType::Expense && $a->subtype === 'cost_of_sales');
        $expenses = $section(fn (LedgerAccount $a) => $a->type === AccountType::Expense && $a->subtype !== 'cost_of_sales');
        $other = $section(fn (LedgerAccount $a) => $a->type === AccountType::Income && $a->subtype === 'other_income');

        // Discounts read as income accounts with a debit balance — negative.
        $net = fn (string $k) => bcadd($revenue[$k] ?? '0.00', $discounts[$k] ?? '0.00', 2);
        $gross = fn (string $k) => bcsub($net($k), $cost[$k] ?? '0.00', 2);
        $operating = fn (string $k) => bcsub($gross($k), $expenses[$k] ?? '0.00', 2);
        $profit = fn (string $k) => bcadd($operating($k), $other[$k] ?? '0.00', 2);

        $figures = fn (string $k) => [
            'net_revenue' => $net($k), 'gross_profit' => $gross($k),
            'operating_profit' => $operating($k), 'net_profit' => $profit($k),
        ];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'compare_from' => $compareFrom?->toDateString(),
            'compare_to' => $compareTo?->toDateString(),
            'revenue' => $revenue,
            'discounts' => $discounts,
            'cost_of_sales' => $cost,
            'expenses' => $expenses,
            'other_income' => $other,
            'totals' => $figures('total'),
            'compare' => $then !== null ? $figures('compare') : null,
            'margin' => bccomp($net('total'), '0', 2) > 0 ? bcmul(bcdiv($profit('total'), $net('total'), 4), '100', 1) : null,
        ];
    }

    // ── Balance sheet ────────────────────────────────────────────────────

    /**
     * What the hospital has, what it owes, and what that leaves for its
     * owners, on one date. Equity includes what earlier years earned and what
     * this year has earned so far, so the two halves agree without anybody
     * having closed anything.
     *
     * @return array<string,mixed>
     */
    public function balanceSheet(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->endOfDay();
        $yearStart = $this->yearStart($asOf);
        $sums = $this->sums(null, $asOf);

        $group = function (AccountType $type, callable $belongs) use ($sums): array {
            $rows = [];
            $total = '0.00';

            foreach ($this->accounts() as $account) {
                if ($account->type !== $type || ! $belongs($account)) {
                    continue;
                }
                $amount = $this->signed($account, $sums[$account->id] ?? null);
                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }
                $rows[] = ['account' => $account, 'amount' => $amount];
                $total = bcadd($total, $amount, 2);
            }

            return ['rows' => $rows, 'total' => $total];
        };

        $nonCurrent = ['fixed_asset', 'contra_asset'];
        $currentAssets = $group(AccountType::Asset, fn ($a) => ! in_array($a->subtype, $nonCurrent, true));
        $fixedAssets = $group(AccountType::Asset, fn ($a) => in_array($a->subtype, $nonCurrent, true));
        $currentLiabilities = $group(AccountType::Liability, fn ($a) => $a->subtype !== 'loan');
        $longTerm = $group(AccountType::Liability, fn ($a) => $a->subtype === 'loan');
        $equity = $group(AccountType::Equity, fn () => true);

        $earlier = $this->profit(null, $yearStart->copy()->subDay());
        $thisYear = $this->profit($yearStart, $asOf);

        $assets = bcadd($currentAssets['total'], $fixedAssets['total'], 2);
        $liabilities = bcadd($currentLiabilities['total'], $longTerm['total'], 2);
        $equityTotal = bcadd(bcadd($equity['total'], $earlier, 2), $thisYear, 2);

        return [
            'as_of' => $asOf->toDateString(),
            'year_start' => $yearStart->toDateString(),
            'current_assets' => $currentAssets,
            'fixed_assets' => $fixedAssets,
            'assets' => $assets,
            'current_liabilities' => $currentLiabilities,
            'long_term_liabilities' => $longTerm,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'earlier_profit' => $earlier,
            'year_profit' => $thisYear,
            'equity_total' => $equityTotal,
            'liabilities_and_equity' => bcadd($liabilities, $equityTotal, 2),
            'balanced' => bccomp($assets, bcadd($liabilities, $equityTotal, 2), 2) === 0,
        ];
    }

    // ── Cash flow ────────────────────────────────────────────────────────

    /**
     * Where the money in the books came from and went, over a range.
     *
     * Read straight from the books' own lines: each movement is put down to
     * whatever was on the other side of its entry — "Patient receivables"
     * money in is patients paying their bills, "Rent" money out is rent. A
     * transfer between two books is shown as one, and nets to nothing across
     * them.
     *
     * @return array{from:string,to:string,books:list<array{account:LedgerAccount,opening:string,in:string,out:string,closing:string}>,inflows:list<array{label:string,amount:string}>,outflows:list<array{label:string,amount:string}>,opening:string,in:string,out:string,closing:string}
     */
    public function cashFlow(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $books = LedgerAccount::query()->books()->ordered()->get();
        $bookIds = $books->pluck('id')->all();

        $before = $this->sums(null, $from->copy()->subDay()->endOfDay(), $bookIds);
        $during = $this->sums($from, $to, $bookIds);

        $perBook = [];
        $opening = $in = $out = '0.00';
        foreach ($books as $book) {
            $o = $this->signed($book, $before[$book->id] ?? null);
            $i = $during[$book->id]['debit'] ?? '0.00';
            $x = $during[$book->id]['credit'] ?? '0.00';
            if (bccomp($o, '0', 2) === 0 && bccomp($i, '0', 2) === 0 && bccomp($x, '0', 2) === 0 && ! $book->is_active) {
                continue;
            }
            $perBook[] = ['account' => $book, 'opening' => $o, 'in' => $i, 'out' => $x, 'closing' => bcsub(bcadd($o, $i, 2), $x, 2)];
            $opening = bcadd($opening, $o, 2);
            $in = bcadd($in, $i, 2);
            $out = bcadd($out, $x, 2);
        }

        // Attribute each book line to the other side of its entry.
        $inflows = [];
        $outflows = [];
        $entryIds = JournalLine::query()->whereIn('ledger_account_id', $bookIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct()->pluck('journal_entry_id');

        $names = $this->accounts()->keyBy('id');

        foreach ($entryIds->chunk(500) as $chunk) {
            $lines = JournalLine::query()->whereIn('journal_entry_id', $chunk)->get(['journal_entry_id', 'ledger_account_id', 'debit', 'credit'])
                ->groupBy('journal_entry_id');

            foreach ($lines as $entryLines) {
                $bookLines = $entryLines->filter(fn ($l) => in_array($l->ledger_account_id, $bookIds, true));
                $others = $entryLines->reject(fn ($l) => in_array($l->ledger_account_id, $bookIds, true));
                $bookNet = $bookLines->reduce(fn ($c, $l) => bcadd($c, bcsub((string) $l->debit, (string) $l->credit, 2), 2), '0.00');

                if ($others->isEmpty()) {
                    continue; // book to book: a transfer, which moves no money in or out of the hospital
                }

                // Spread the money over the other side in proportion — one
                // receipt that settles two kinds of thing reads as both.
                $side = bccomp($bookNet, '0', 2) >= 0 ? 'credit' : 'debit';
                $weights = $others->filter(fn ($l) => bccomp((string) $l->{$side}, '0', 2) > 0);
                $weightTotal = $weights->reduce(fn ($c, $l) => bcadd($c, (string) $l->{$side}, 2), '0.00');
                if (bccomp($weightTotal, '0', 2) === 0) {
                    continue;
                }

                $abs = ltrim($bookNet, '-');
                foreach ($weights as $l) {
                    $share = bcdiv(bcmul($abs, (string) $l->{$side}, 6), $weightTotal, 2);
                    $label = $this->flowLabel($names[$l->ledger_account_id] ?? null, $side === 'credit');
                    if ($side === 'credit') {
                        $inflows[$label] = bcadd($inflows[$label] ?? '0.00', $share, 2);
                    } else {
                        $outflows[$label] = bcadd($outflows[$label] ?? '0.00', $share, 2);
                    }
                }
            }
        }

        arsort($inflows, SORT_NUMERIC);
        arsort($outflows, SORT_NUMERIC);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'books' => $perBook,
            'inflows' => array_map(fn ($l, $a) => ['label' => $l, 'amount' => $a], array_keys($inflows), $inflows),
            'outflows' => array_map(fn ($l, $a) => ['label' => $l, 'amount' => $a], array_keys($outflows), $outflows),
            'opening' => $opening,
            'in' => $in,
            'out' => $out,
            'closing' => bcsub(bcadd($opening, $in, 2), $out, 2),
        ];
    }

    // ── One account ──────────────────────────────────────────────────────

    /**
     * An account's ledger over a range: what it stood at before, every line
     * in it with the balance after, and where it ended.
     *
     * @return array{opening:string,closing:string,debit:string,credit:string,lines:Collection<int,array{line:JournalLine,balance:numeric-string}>}
     */
    public function statement(LedgerAccount $account, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $openingStart = $account->type->isTemporary() ? $this->yearStart($from) : null;

        $opening = $this->signed($account, $this->sums($openingStart, $from->copy()->subDay()->endOfDay(), [$account->id])[$account->id] ?? null);

        $lines = JournalLine::query()
            ->with('entry')
            ->where('ledger_account_id', $account->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('journal_entry_id')->orderBy('id')
            ->get();

        $running = $opening;
        $debit = $credit = '0.00';
        $rows = $lines->map(function (JournalLine $line) use (&$running, &$debit, &$credit, $account) {
            $running = bcadd($running, $account->type->balance((string) $line->debit, (string) $line->credit), 2);
            $debit = bcadd($debit, (string) $line->debit, 2);
            $credit = bcadd($credit, (string) $line->credit, 2);

            return ['line' => $line, 'balance' => $running];
        });

        return ['opening' => $opening, 'closing' => $running, 'debit' => $debit, 'credit' => $credit, 'lines' => $rows];
    }

    /**
     * Every account's balance on a date, as each one normally reads — the
     * figure shown beside it in the chart. Income and expenses are this year's.
     *
     * @return array<int,string>
     */
    public function balances(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->endOfDay();
        $permanent = $this->sums(null, $asOf);
        $thisYear = $this->sums($this->yearStart($asOf), $asOf);
        $out = [];

        foreach ($this->accounts() as $account) {
            $out[$account->id] = $this->signed($account, $account->type->isTemporary() ? ($thisYear[$account->id] ?? null) : ($permanent[$account->id] ?? null));
        }

        return $out;
    }

    // ── The overview ─────────────────────────────────────────────────────

    /**
     * The handful of figures someone opening the finance pages wants first.
     *
     * @return array<string,mixed>
     */
    public function overview(Carbon $from, Carbon $to): array
    {
        $statement = $this->incomeStatement($from, $to);
        $balances = $this->balances(Carbon::now());
        $books = LedgerAccount::query()->books()->active()->ordered()->get();

        $byKey = fn (string $key) => $this->chart->has($key) ? ($balances[$this->chart->key($key)->id] ?? '0.00') : '0.00';

        // Six months of earned against spent, oldest first.
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->startOfMonth()->subMonthsNoOverflow($i);
            $is = $this->incomeStatement($month->copy(), $month->copy()->endOfMonth());
            $income = bcadd($is['totals']['net_revenue'], $is['other_income']['total'], 2);
            $spent = bcadd($is['cost_of_sales']['total'], $is['expenses']['total'], 2);
            $trend[$month->format('M')] = ['income' => $income, 'expenses' => $spent];
        }

        $expenseRows = collect([...$statement['cost_of_sales']['rows'], ...$statement['expenses']['rows']])
            ->sortByDesc(fn ($r) => (float) $r['amount'])->take(6);

        return [
            'statement' => $statement,
            'books' => $books->map(fn ($b) => ['account' => $b, 'balance' => $balances[$b->id] ?? '0.00'])->all(),
            'cash' => $books->reduce(fn ($c, $b) => bcadd($c, $balances[$b->id] ?? '0.00', 2), '0.00'),
            'receivables' => bcadd($byKey('receivable'), $byKey('receivable.insurance'), 2),
            'payables' => $byKey('payable'),
            'inventory' => $byKey('inventory'),
            'deposits' => bcadd($byKey('card_deposits'), $byKey('insurer_floats'), 2),
            'tax' => $byKey('tax.output'),
            'trend' => $trend,
            'top_expenses' => $expenseRows->mapWithKeys(fn ($r) => [$r['account']->name => $r['amount']])->all(),
            'recent' => JournalEntry::query()->with('lines.account')->latest('date')->latest('id')->take(8)->get(),
        ];
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /** The first day of the year a date belongs to. */
    public function yearStart(Carbon $date): Carbon
    {
        $year = FinancialYear::query()
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();

        return $year !== null ? $year->starts_on->copy()->startOfDay() : $date->copy()->startOfYear();
    }

    /** Income less expenses over a range; null `from` is the beginning of time. */
    public function profit(?Carbon $from, Carbon $to): string
    {
        if ($from !== null && $from->gt($to)) {
            return '0.00';
        }

        $sums = $this->sums($from, $to);
        $profit = '0.00';

        foreach ($this->accounts() as $account) {
            if (! $account->type->isTemporary()) {
                continue;
            }
            $s = $sums[$account->id] ?? null;
            // Credits less debits: income adds, expense takes away.
            $profit = bcadd($profit, bcsub($s['credit'] ?? '0.00', $s['debit'] ?? '0.00', 2), 2);
        }

        return $profit;
    }

    /**
     * Debits and credits per account over a range, in one query.
     *
     * @param  list<int>|null  $only
     * @return array<int, array{debit:string,credit:string}>
     */
    private function sums(?Carbon $from, ?Carbon $to, ?array $only = null): array
    {
        $rows = JournalLine::query()
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to->toDateString()))
            ->when($only !== null, fn ($q) => $q->whereIn('ledger_account_id', $only))
            ->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->ledger_account_id] = ['debit' => $this->money($row->debit), 'credit' => $this->money($row->credit)];
        }

        return $out;
    }

    /** A database sum as a scale-2 string: MySQL hands back an exact decimal, SQLite a float. */
    private function money(mixed $value): string
    {
        if (is_string($value) && is_numeric($value) && ! str_contains(strtolower($value), 'e')) {
            return bcadd($value, '0', 2);
        }

        return number_format((float) $value, 2, '.', '');
    }

    /** @param array{debit:string,credit:string}|null $sum */
    private function signed(LedgerAccount $account, ?array $sum): string
    {
        return $account->type->balance($sum['debit'] ?? '0.00', $sum['credit'] ?? '0.00');
    }

    /** @var Collection<int, LedgerAccount>|null */
    private ?Collection $accounts = null;

    /** The chart, read once per statement. @return Collection<int, LedgerAccount> */
    private function accounts(): Collection
    {
        return $this->accounts ??= LedgerAccount::query()->ordered()->get();
    }

    private function retainedAccount(): ?LedgerAccount
    {
        return $this->chart->has('retained_earnings') ? $this->chart->key('retained_earnings') : null;
    }

    /** How a cash-flow line is described, by the account on the other side. */
    private function flowLabel(?LedgerAccount $account, bool $in): string
    {
        if ($account === null) {
            return $in ? 'Other receipts' : 'Other payments';
        }

        return match ($account->system_key) {
            'receivable' => 'Patients paying their bills',
            'receivable.insurance' => 'Insurers settling claims',
            'card_deposits' => $in ? 'Prepaid card top-ups' : 'Card refunds',
            'insurer_floats' => $in ? 'Insurer deposits' : 'Insurer deposits returned',
            'payable' => 'Suppliers paid',
            'capital' => $in ? 'Capital put in' : 'Capital withdrawn',
            default => $account->name,
        };
    }
}
