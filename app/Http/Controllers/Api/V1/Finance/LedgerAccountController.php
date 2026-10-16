<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\AccountType;
use App\Exceptions\AccountRuleException;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\LedgerAccountRequest;
use App\Http\Resources\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Services\Finance\AccountManager;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ApiResponse;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The chart of accounts (web: admin.finance.accounts) and one account's ledger. */
class LedgerAccountController extends Controller
{
    use AnswersFinanceRequests;

    /** finance.view. ?type=, ?q= (name or code prefix), ?off=1 to include switched-off accounts. Grouped as the web chart groups them. */
    public function index(Request $request, FinancialStatements $statements, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $q = trim((string) $request->query('q', ''));
        $type = AccountType::tryFrom((string) $request->query('type', ''));

        $accounts = LedgerAccount::query()
            ->when(! $request->boolean('off'), fn ($qq) => $qq->where('is_active', true))
            ->when($type !== null, fn ($qq) => $qq->where('type', $type->value))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%")))
            ->ordered()->get();

        LedgerAccountResource::$balances = $statements->balances(Carbon::now());
        LedgerAccountResource::$usage = $manager->usage();

        $groups = collect(AccountType::cases())
            ->map(fn (AccountType $t) => ['type' => $t->value, 'label' => $t->plural(), 'accounts' => LedgerAccountResource::collection($accounts->where('type', $t)->values())->resolve()])
            ->filter(fn ($g) => $g['accounts'] !== [])
            ->values();

        return ApiResponse::success($groups);
    }

    /** finance.view. The account's ledger for ?from/?to (default this month): opening, closing, debit, credit and each line with the running balance. */
    public function show(Request $request, LedgerAccount $account, FinancialStatements $statements, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.view');

        [$from, $to] = ReportRange::read($request->query('from'), $request->query('to'));
        $st = $statements->statement($account, Carbon::parse($from), Carbon::parse($to));

        LedgerAccountResource::$balances = $statements->balances(Carbon::now());
        LedgerAccountResource::$usage = $manager->usage();

        return ApiResponse::success([
            'account' => (new LedgerAccountResource($account))->resolve(),
            'from' => $from,
            'to' => $to,
            'opening' => $st['opening'],
            'closing' => $st['closing'],
            'debit' => $st['debit'],
            'credit' => $st['credit'],
            'lines' => $st['lines']->map(fn ($r) => [
                'date' => $r['line']->date?->toDateString(),
                'entry_uuid' => $r['line']->entry->uuid,
                'entry_no' => $r['line']->entry->entry_no,
                'memo' => $r['line']->memo ?: $r['line']->entry->memo,
                'party' => $r['line']->entry->party,
                'reference' => $r['line']->entry->reference,
                'debit' => (string) $r['line']->debit,
                'credit' => (string) $r['line']->credit,
                'balance' => $r['balance'],
            ])->values(),
        ]);
    }

    /** finance.manage. {code, name, type, is_book?, description?, is_active?} */
    public function store(LedgerAccountRequest $request, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        try {
            $account = $manager->save(null, $request->validated());
        } catch (AccountRuleException $e) {
            return $this->refuse($e, $e->field);
        }

        return ApiResponse::success((new LedgerAccountResource($account))->resolve(), 'Account added.', 201);
    }

    /** finance.manage. Same fields; a system account keeps its kind and stays on, a used one keeps its kind. */
    public function update(LedgerAccountRequest $request, LedgerAccount $account, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.manage');

        try {
            $account = $manager->save($account, $request->validated());
        } catch (AccountRuleException $e) {
            return $this->refuse($e, $e->field);
        }

        return ApiResponse::success((new LedgerAccountResource($account->refresh()))->resolve(), 'Account saved.');
    }

    /** finance.manage. Switch off / back on. */
    public function toggle(LedgerAccount $account, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.manage');

        try {
            $account = $manager->toggleActive($account);
        } catch (AccountRuleException $e) {
            return $this->refuse($e, $e->field);
        }

        return ApiResponse::success((new LedgerAccountResource($account->refresh()))->resolve(), $account->is_active ? 'Switched back on.' : 'Switched off — it keeps its history.');
    }

    /** finance.manage. Only an account nothing has used. */
    public function destroy(LedgerAccount $account, AccountManager $manager): JsonResponse
    {
        $this->authorizeBooks('finance.manage');

        try {
            $manager->delete($account);
        } catch (AccountRuleException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success(null, 'Account deleted.');
    }
}
