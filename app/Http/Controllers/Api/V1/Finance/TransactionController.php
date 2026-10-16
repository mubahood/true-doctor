<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\JournalKind;
use App\Exceptions\ClosedPeriodException;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MoneyEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The cashbook (web: admin.finance.transactions): every movement through a
 * money book, one row per movement; and money in / money out / transfer.
 */
class TransactionController extends Controller
{
    use AnswersFinanceRequests;

    /**
     * finance.view. Filters: book (ledger account id), kind (JournalKind), dir (in|out),
     * from, to (dates), q. Meta carries the page's in/out totals and, with `book`, its balance.
     */
    public function index(Request $request, FinancialStatements $statements): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $books = LedgerAccount::query()->books()->ordered()->get();
        $book = (string) $request->query('book', '');
        $dir = (string) $request->query('dir', '');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $kind = (string) $request->query('kind', '');
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $rows = JournalLine::query()
            ->with(['account', 'entry.lines.account'])
            ->whereIn('ledger_account_id', $book !== '' ? [(int) $book] : $books->pluck('id')->all())
            ->when($dir === 'in', fn ($qq) => $qq->where('debit', '>', 0))
            ->when($dir === 'out', fn ($qq) => $qq->where('credit', '>', 0))
            ->when($from !== null, fn ($qq) => $qq->where('date', '>=', $from))
            ->when($to !== null, fn ($qq) => $qq->where('date', '<=', $to))
            ->when($kind !== '' && JournalKind::tryFrom($kind), fn ($qq) => $qq->whereHas('entry', fn ($e) => $e->where('kind', $kind)))
            ->when($q !== '', fn ($qq) => $qq->whereHas('entry', fn ($e) => $e->where(fn ($w) => $w
                ->where('memo', 'like', "%{$q}%")
                ->orWhere('party', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")
                ->orWhere('entry_no', 'like', "%{$q}%"))))
            ->orderByDesc('date')->orderByDesc('journal_entry_id')->orderByDesc('id')
            ->paginate($this->perPage($request));

        $in = $rows->getCollection()->reduce(fn ($c, $l) => bcadd($c, (string) $l->debit, 2), '0.00');
        $out = $rows->getCollection()->reduce(fn ($c, $l) => bcadd($c, (string) $l->credit, 2), '0.00');

        $selected = $book !== '' ? $books->firstWhere('id', (int) $book) : null;

        $items = $rows->getCollection()->map(fn (JournalLine $l) => [
            'id' => $l->id,
            'date' => $l->date?->toDateString(),
            'book' => ['id' => $l->account->id, 'name' => $l->account->name],
            'in' => (string) $l->debit,
            'out' => (string) $l->credit,
            'entry' => [
                'uuid' => $l->entry->uuid,
                'entry_no' => $l->entry->entry_no,
                'kind' => $l->entry->kind->value,
                'kind_label' => $l->entry->kind->label(),
                'memo' => $l->memo ?: $l->entry->memo,
                'party' => $l->entry->party,
                'reference' => $l->entry->reference,
                'is_reversed' => $l->entry->isReversed(),
                // What the other side of the entry was (the category, or the other book).
                'counter' => $l->entry->lines->where('id', '!=', $l->id)->map(fn ($o) => $o->account?->name)->filter()->unique()->values(),
            ],
        ])->values();

        return ApiResponse::paginated($rows, $items, [
            'page_in' => $in,
            'page_out' => $out,
            'book_balance' => $selected === null ? null : ($statements->balances(Carbon::now())[$selected->id] ?? '0.00'),
        ]);
    }

    /**
     * finance.record. {kind: in|out|transfer, date, amount, reference?, and either
     * book_id, account_id, memo, party? (in/out) or from_book_id, to_book_id, memo? (transfer)}.
     */
    public function store(MoneyEntryRequest $request, CashbookService $cashbook): JsonResponse
    {
        $this->authorizeBooks('finance.record');
        app(ChartOfAccounts::class)->ensure();

        $d = $request->validated();
        $by = $request->user()->id;

        try {
            $entry = match ($d['kind']) {
                'transfer' => $cashbook->transfer([
                    'date' => $d['date'], 'from_book_id' => (int) $d['from_book_id'], 'to_book_id' => (int) $d['to_book_id'],
                    'amount' => (string) $d['amount'], 'memo' => $d['memo'] ?? null, 'reference' => $d['reference'] ?? null,
                ], $by),
                default => $cashbook->{$d['kind'] === 'out' ? 'moneyOut' : 'moneyIn'}([
                    'date' => $d['date'], 'book_id' => (int) $d['book_id'], 'account_id' => (int) $d['account_id'],
                    'amount' => (string) $d['amount'], 'memo' => $d['memo'],
                    'party' => $d['party'] ?? null, 'reference' => $d['reference'] ?? null,
                ], $by),
            };
        } catch (\DomainException|ClosedPeriodException $e) {
            return $this->refuse($e, $d['kind'] === 'transfer' ? 'to_book_id' : 'account_id');
        }

        $label = match ($d['kind']) {
            'transfer' => 'Transfer recorded',
            'out' => 'Money out recorded',
            default => 'Money in recorded',
        };

        return ApiResponse::success((new JournalEntryResource($entry->load('lines.account')))->resolve(), $label.' — '.$entry->entry_no.'.', 201);
    }
}
