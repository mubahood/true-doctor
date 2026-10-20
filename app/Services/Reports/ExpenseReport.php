<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Enums\JournalKind;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Expenditure: the clinic's expenditure book, every shilling paid out of its
 * money books (cash, bank, mobile money), one line per account it went to.
 *
 * Cash basis, read from the books as the Transactions page reads them: each
 * "Money out" entry (docs/finance.md, Recorded by hand), whatever it paid for,
 * and each delivery of stock paid for on the spot (a stock entry with a money
 * book on its other side, when the finance settings say deliveries are paid
 * from a book). Every line says what KIND of spending it was, so the running
 * expenses (expense accounts, not cost of sales) stand as their own total and
 * agree with the income statement's running costs, while equipment, stock,
 * a supplier paid and the owner's drawings are still in the book.
 *
 * An entry reversed later still counts in its own month and its reversal
 * counts, negative, in the month it was reversed, exactly as the ledger
 * reads; so a month never changes after it is printed.
 */
class ExpenseReport extends MonthlyReport
{
    public const RUNNING = 'Running expense';

    public function title(): string
    {
        return 'Expenditure report';
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Account', 'type' => 'text'],
            ['key' => 'kind', 'label' => 'Kind', 'type' => 'text'],
            ['key' => 'paid_to', 'label' => 'Paid to', 'type' => 'text'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['key' => 'method', 'label' => 'Paid from', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
        ];
    }

    /** What kind of spending a line to this account is. */
    public static function kindOf(string $type, ?string $subtype, ?string $systemKey): string
    {
        return match (true) {
            $type === AccountType::Expense->value && $subtype === 'cost_of_sales' => 'Cost of sales',
            $type === AccountType::Expense->value => self::RUNNING,
            $type === AccountType::Asset->value && $systemKey === 'inventory' => 'Stock purchase',
            $type === AccountType::Asset->value => 'Equipment and other assets',
            $type === AccountType::Liability->value && $systemKey === 'payable' => 'Paying a supplier',
            $type === AccountType::Liability->value => 'Paying what is owed',
            $type === AccountType::Equity->value => 'Owner drawings',
            default => 'Income paid back',
        };
    }

    /**
     * Entries that paid money out: money out by hand, and stock deliveries
     * paid from a money book.
     *
     * @return Builder<JournalEntry>
     */
    private function payingOut(): Builder
    {
        $withBook = JournalLine::query()->join('ledger_accounts as lb', 'lb.id', '=', 'journal_lines.ledger_account_id')
            ->where('lb.is_book', true)->select('journal_lines.journal_entry_id');

        return JournalEntry::query()->where(fn ($q) => $q->where('kind', JournalKind::Expense->value)
            ->orWhere(fn ($s) => $s->where('kind', JournalKind::System->value)
                ->where('source_type', (new StockMovement)->getMorphClass())
                ->whereIn('id', $withBook)));
    }

    /**
     * Those dated in the period, and reversals of them dated in it.
     *
     * @return Builder<JournalEntry>
     */
    private function entries(ReportPeriod $p): Builder
    {
        return JournalEntry::query()
            ->where('journal_entries.date', '>=', $p->from)->where('journal_entries.date', '<=', $p->to)
            ->where(fn ($q) => $q->whereIn('id', $this->payingOut()->select('id'))
                ->orWhere(fn ($r) => $r->where('kind', JournalKind::Reversal->value)
                    ->whereIn('reverses_id', $this->payingOut()->select('id'))));
    }

    /**
     * Their lines, with the account each is on.
     *
     * @return Builder<JournalLine>
     */
    private function lines(ReportPeriod $p, bool $books): Builder
    {
        return JournalLine::query()
            ->join('ledger_accounts as la', 'la.id', '=', 'journal_lines.ledger_account_id')
            ->whereIn('journal_lines.journal_entry_id', $this->entries($p)->select('journal_entries.id'))
            ->where('la.is_book', $books);
    }

    public function rows(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator
    {
        $lines = $this->lines($period, false)->select('journal_lines.*')
            ->with(['account:id,code,name,type,subtype,system_key,is_book', 'entry.lines.account:id,name,is_book', 'entry.reverses:id,entry_no,party,reference'])
            ->orderBy('journal_lines.date')->orderBy('journal_lines.journal_entry_id')->orderBy('journal_lines.id');

        foreach (self::window($lines, $offset, $limit) as $chunk) {
            foreach ($chunk as $line) {
                $entry = $line->entry;
                $account = $line->account;
                $reversal = $entry->kind === JournalKind::Reversal;
                $books = $entry->lines->filter(fn ($l) => (bool) $l->account?->is_book)->map(fn ($l) => $l->account->name)->unique()->implode(', ');

                yield [
                    'date' => $entry->date->toDateString(),
                    'reference' => trim(($entry->reference ?: ($reversal ? $entry->reverses?->reference : null)) ?? '') ?: $entry->entry_no,
                    'category' => $account->label(),
                    'kind' => self::kindOf($account->type->value, $account->subtype, $account->system_key),
                    'paid_to' => $entry->party ?: ($reversal ? $entry->reverses?->party : null),
                    'description' => trim((string) ($line->memo ?: $entry->memo)),
                    'method' => $books,
                    // Debit less credit: money out is positive, its reversal negative.
                    'amount' => bcsub((string) $line->debit, (string) $line->credit, 2),
                ];
            }
        }
    }

    public function count(ReportPeriod $period): int
    {
        return $this->lines($period, false)->count();
    }

    public function totals(ReportPeriod $period): array
    {
        $out = [];
        foreach ($this->byMethod($period) as $m) {
            $out[] = ['label' => $m['method'], 'value' => $m['amount'], 'type' => 'money'];
        }
        $out[] = ['label' => 'Running expenses', 'value' => $this->running($period), 'type' => 'money'];
        $out[] = ['label' => 'Total paid out', 'value' => $this->total($period), 'type' => 'money'];

        return $out;
    }

    /** Everything paid out in the period. */
    public function total(ReportPeriod $period): string
    {
        return $this->sumOf($this->lines($period, false));
    }

    /** The running expenses alone: expense accounts other than cost of sales, as the income statement's running costs. */
    public function running(ReportPeriod $period): string
    {
        return $this->sumOf($this->lines($period, false)->where('la.type', AccountType::Expense->value)
            ->where(fn ($q) => $q->whereNull('la.subtype')->orWhere('la.subtype', '!=', 'cost_of_sales')));
    }

    /** @param Builder<JournalLine> $q */
    private function sumOf(Builder $q): string
    {
        $r = $q->toBase()->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as d, COALESCE(SUM(journal_lines.credit), 0) as c')->first();

        return bcsub(self::dec($r->d ?? 0), self::dec($r->c ?? 0), 2);
    }

    /** @return list<array{category:string,kind:string,entries:int,amount:string}> */
    private function byCategory(ReportPeriod $period): array
    {
        $rows = $this->lines($period, false)->toBase()
            ->selectRaw('la.code as code, la.name as name, la.type as type, la.subtype as subtype, la.system_key as skey, COUNT(*) as n, COALESCE(SUM(journal_lines.debit), 0) as d, COALESCE(SUM(journal_lines.credit), 0) as c')
            ->groupBy('la.id', 'la.code', 'la.name', 'la.type', 'la.subtype', 'la.system_key')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'category' => $r->code.' · '.$r->name,
                'kind' => self::kindOf((string) $r->type, $r->subtype, $r->skey),
                'entries' => (int) $r->n,
                'amount' => bcsub(self::dec($r->d), self::dec($r->c), 2),
            ];
        }
        usort($out, fn ($a, $b) => bccomp($b['amount'], $a['amount'], 2));

        return $out;
    }

    /** @return list<array{method:string,amount:string}> */
    private function byMethod(ReportPeriod $period): array
    {
        $rows = $this->lines($period, true)->toBase()
            ->selectRaw('la.name as name, COALESCE(SUM(journal_lines.debit), 0) as d, COALESCE(SUM(journal_lines.credit), 0) as c')
            ->groupBy('la.id', 'la.name')->get();

        $out = [];
        foreach ($rows as $r) {
            // Out of a book is a credit to it.
            $out[] = ['method' => (string) $r->name, 'amount' => bcsub(self::dec($r->c), self::dec($r->d), 2)];
        }
        usort($out, fn ($a, $b) => bccomp($b['amount'], $a['amount'], 2));

        return $out;
    }

    /**
     * Calendar weeks, Monday to Sunday, cut to the period.
     *
     * @return list<array{week:string,amount:string}>
     */
    private function byWeek(ReportPeriod $period): array
    {
        $byDate = [];
        foreach ($this->lines($period, false)->toBase()
            ->selectRaw('journal_lines.date as day, COALESCE(SUM(journal_lines.debit), 0) as d, COALESCE(SUM(journal_lines.credit), 0) as c')
            ->groupBy('journal_lines.date')->get() as $r) {
            $byDate[substr((string) $r->day, 0, 10)] = bcsub(self::dec($r->d), self::dec($r->c), 2);
        }

        $weeks = [];
        $start = Carbon::parse($period->from);
        $end = Carbon::parse($period->to);
        for ($w = $start->copy(); $w->lte($end); $w = $w->copy()->startOfWeek(Carbon::MONDAY)->addWeek()) {
            $last = $w->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()->min($end);
            $amount = '0.00';
            for ($d = $w->copy(); $d->lte($last); $d->addDay()) {
                $amount = bcadd($amount, $byDate[$d->toDateString()] ?? '0.00', 2);
            }
            $weeks[] = ['week' => $w->format('j M').' to '.$last->format('j M'), 'amount' => $amount];
        }

        return $weeks;
    }

    public function summary(ReportPeriod $period): array
    {
        $total = $this->total($period);
        $running = $this->running($period);
        $before = $this->total($period->previous());
        $runningBefore = $this->running($period->previous());
        $categories = $this->byCategory($period);
        $money = ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'];

        $kinds = [];
        foreach ($categories as $c) {
            $kinds[$c['kind']] = bcadd($kinds[$c['kind']] ?? '0.00', $c['amount'], 2);
        }
        uasort($kinds, fn ($a, $b) => bccomp($b, $a, 2));

        return [
            'figures' => [
                ['label' => 'Total paid out', 'value' => $total, 'type' => 'money', 'note' => count($categories).' accounts'],
                ['label' => 'Running expenses', 'value' => $running, 'type' => 'money', 'note' => 'as the income statement'],
                ['label' => 'Stock purchases', 'value' => $kinds['Stock purchase'] ?? '0.00', 'type' => 'money', 'note' => 'paid for on delivery'],
                ['label' => 'Change in total paid out', 'value' => self::change($total, $before), 'type' => 'percent', 'note' => 'against '.$period->previous()->label()],
            ],
            'tables' => [
                [
                    'key' => 'by_kind', 'title' => 'By kind',
                    'columns' => [['key' => 'kind', 'label' => 'Kind', 'type' => 'text'], $money],
                    'rows' => array_map(fn ($k, $a) => ['kind' => (string) $k, 'amount' => $a], array_keys($kinds), array_values($kinds)),
                    'total' => ['kind' => 'Total paid out', 'amount' => $total],
                    'note' => '"Running expense" is spending on expense accounts other than cost of sales: the running costs on the income statement.',
                ],
                [
                    'key' => 'by_category', 'title' => 'By account',
                    'columns' => [['key' => 'category', 'label' => 'Account', 'type' => 'text'], ['key' => 'kind', 'label' => 'Kind', 'type' => 'text'], ['key' => 'entries', 'label' => 'Lines', 'type' => 'int'], $money],
                    'rows' => $categories,
                    'total' => ['category' => 'Total', 'entries' => array_sum(array_column($categories, 'entries')), 'amount' => $total],
                ],
                [
                    'key' => 'by_method', 'title' => 'By book paid from',
                    'columns' => [['key' => 'method', 'label' => 'Paid from', 'type' => 'text'], $money],
                    'rows' => $this->byMethod($period),
                    'total' => ['method' => 'Total', 'amount' => $total],
                ],
                [
                    'key' => 'by_week', 'title' => 'By week',
                    'columns' => [['key' => 'week', 'label' => 'Week', 'type' => 'text'], $money],
                    'rows' => $this->byWeek($period),
                    'total' => ['week' => 'Total', 'amount' => $total],
                ],
                self::comparison([
                    'Total paid out' => [$total, $before],
                    'Running expenses' => [$running, $runningBefore],
                ], $period),
            ],
        ];
    }
}
