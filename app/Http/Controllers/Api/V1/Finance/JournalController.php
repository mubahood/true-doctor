<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\JournalKind;
use App\Exceptions\ClosedPeriodException;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JournalEntryRequest;
use App\Http\Requests\Api\OpeningBalancesRequest;
use App\Http\Requests\Api\ReverseEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The general journal (web: admin.finance.journal). */
class JournalController extends Controller
{
    use AnswersFinanceRequests;

    /** finance.view. Filters: kind, from, to, q. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $kind = (string) $request->query('kind', '');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $rows = JournalEntry::query()
            ->withCount('lines')
            ->when($kind !== '' && JournalKind::tryFrom($kind), fn ($qq) => $qq->where('kind', $kind))
            ->when($from !== null, fn ($qq) => $qq->where('date', '>=', $from))
            ->when($to !== null, fn ($qq) => $qq->where('date', '<=', $to))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w
                ->where('memo', 'like', "%{$q}%")
                ->orWhere('entry_no', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")
                ->orWhere('party', 'like', "%{$q}%")))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, JournalEntryResource::collection($rows->getCollection())->resolve());
    }

    /** finance.view. One entry with its lines and where it came from. */
    public function show(JournalEntry $entry): JsonResponse
    {
        $this->authorizeBooks('finance.view');

        $entry->load(['lines.account', 'createdBy', 'reverses', 'reversedBy', 'source']);

        return ApiResponse::success((new JournalEntryResource($entry))->resolve());
    }

    /** finance.manage. {date, memo, reference?, lines:[{account_id, debit?, credit?, memo?}]} - must balance. */
    public function store(JournalEntryRequest $request, CashbookService $cashbook): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        $d = $request->validated();

        try {
            $entry = $cashbook->journal(['date' => $d['date'], 'memo' => $d['memo'], 'reference' => $d['reference'] ?? null], $d['lines'], $request->user()->id);
        } catch (\DomainException|ClosedPeriodException $e) {
            return $this->refuse($e, 'lines');
        }

        return ApiResponse::success((new JournalEntryResource($entry->load('lines.account')))->resolve(), 'Journal '.$entry->entry_no.' posted.', 201);
    }

    /** finance.manage. {date, balances:{account_id: amount}}. */
    public function opening(OpeningBalancesRequest $request, CashbookService $cashbook): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        $d = $request->validated();

        try {
            $entry = $cashbook->openingBalances($d['date'], $d['balances'], $request->user()->id);
        } catch (\DomainException|ClosedPeriodException $e) {
            return $this->refuse($e, 'balances');
        }

        return ApiResponse::success((new JournalEntryResource($entry->load('lines.account')))->resolve(), 'Opening balances posted as '.$entry->entry_no.'.', 201);
    }

    /** finance.manage. {reason}. Only an entry a person wrote, once. */
    public function reverse(ReverseEntryRequest $request, JournalEntry $entry, LedgerService $ledger): JsonResponse
    {
        $this->authorizeBooks('finance.manage');

        try {
            $reversal = $ledger->reverse($entry, $request->validated('reason'), $request->user()->id);
        } catch (\DomainException|ClosedPeriodException $e) {
            return $this->refuse($e, 'reason');
        }

        return ApiResponse::success((new JournalEntryResource($reversal->load('lines.account')))->resolve(), $entry->entry_no.' reversed by '.$reversal->entry_no.'.', 201);
    }
}
