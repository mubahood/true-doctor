<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\AccountType;
use App\Enums\JournalKind;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FinanceSettingsRequest;
use App\Http\Resources\FinancialStatementResource;
use App\Http\Resources\JournalEntryResource;
use App\Services\Finance\AccountManager;
use App\Services\Finance\BooksBackfill;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinancialStatements;
use App\Support\ApiResponse;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The finance front page (web: admin.finance.index): where the money is, what
 * the period earned and spent, the receivables/payables, the six-month trend,
 * the largest costs, the latest entries, and whether the books are complete.
 * Plus the pickers the money forms need, the "bring the books up to date"
 * action and the finance settings.
 */
class FinanceController extends Controller
{
    use AnswersFinanceRequests;

    /** finance.view. ?range=today|week|month|last-month|quarter|year */
    public function overview(Request $request, FinancialStatements $statements): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $range = (string) $request->query('range', 'month');
        $range = array_key_exists($range, ReportRange::presets()) ? $range : 'month';
        [$from, $to] = ReportRange::forPreset($range);
        $f = $statements->overview(Carbon::parse($from), Carbon::parse($to));

        $user = $request->user();

        return ApiResponse::success([
            'range' => $range,
            'presets' => ReportRange::presets(),
            'from' => $from,
            'to' => $to,
            'income_statement' => (new FinancialStatementResource(['report' => 'income', 'data' => $f['statement']]))->resolve(),
            'books' => array_map(fn ($b) => ['id' => $b['account']->id, 'code' => $b['account']->code, 'name' => $b['account']->name, 'balance' => $b['balance']], $f['books']),
            'cash' => $f['cash'],
            'receivables' => $f['receivables'],
            'payables' => $f['payables'],
            'inventory' => $f['inventory'],
            'deposits' => $f['deposits'],
            'tax' => $f['tax'],
            'trend' => collect($f['trend'])->map(fn ($v, $month) => ['month' => $month, 'income' => $v['income'], 'expenses' => $v['expenses']])->values(),
            'top_expenses' => collect($f['top_expenses'])->map(fn ($amount, $name) => ['name' => $name, 'amount' => $amount])->values(),
            'recent' => JournalEntryResource::collection($f['recent'])->resolve(),
            'pending' => app(BooksBackfill::class)->pending(),
            'can' => ['record' => $user->can('finance.record'), 'manage' => $user->can('finance.manage')],
        ]);
    }

    /** finance.view. Everything a client needs to draw the money and journal forms. */
    public function options(Request $request, CashbookService $cashbook, AccountManager $accounts): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $books = fn () => \App\Models\LedgerAccount::query()->books()->active()->ordered()->get()
            ->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'label' => $b->label()])->values();

        $journalAccounts = [];
        foreach (\App\Models\LedgerAccount::query()->active()->ordered()->get() as $a) {
            $journalAccounts[$a->type->plural()][] = ['id' => $a->id, 'label' => $a->label()];
        }

        $nextCodes = [];
        foreach (AccountType::cases() as $t) {
            $nextCodes[$t->value] = $accounts->nextCode($t);
        }

        return ApiResponse::success([
            'books' => $books(),
            'money_categories' => ['in' => $cashbook->categories('in'), 'out' => $cashbook->categories('out')],
            'journal_accounts' => $journalAccounts,
            'opening_accounts' => $cashbook->openingAccounts()->map(fn ($a) => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type->value])->values(),
            'has_opening' => $cashbook->hasOpening(),
            'journal_kinds' => JournalKind::options(),
            'account_types' => AccountType::options(),
            'next_account_codes' => $nextCodes,
            'presets' => ReportRange::presets(),
            'reports' => collect(\App\Livewire\Finance\Statements::REPORTS)->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0]])->values(),
        ]);
    }

    /** finance.manage. Post everything the books are missing (the web's "Bring them up to date"). */
    public function catchUp(BooksBackfill $backfill): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        $posted = array_sum($backfill->run());

        return ApiResponse::success(
            ['posted' => $posted, 'pending' => $backfill->pending()],
            $posted > 0 ? $posted.' records brought into the books.' : 'The books were already up to date.',
        );
    }

    /** finance.manage. */
    public function settings(FinanceSettings $settings): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        return ApiResponse::success($this->settingsPayload($settings));
    }

    /** finance.manage. Send any of method_books{method:id}, card_topup_book, insurer_deposit_book, stock_credit_account; null/"" clears to the default. */
    public function updateSettings(FinanceSettingsRequest $request, FinanceSettings $settings): JsonResponse
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        $settings->save($request->validated());

        return ApiResponse::success($this->settingsPayload($settings), 'Finance settings saved — they apply from the next posting.');
    }

    /** @return array<string,mixed> */
    private function settingsPayload(FinanceSettings $settings): array
    {
        $stored = $settings->stored();
        $opt = fn ($a) => ['id' => $a->id, 'label' => $a->label()];
        $methods = [];
        foreach (array_keys(ChartOfAccounts::METHOD_BOOKS) as $m) {
            $methods[] = ['method' => $m, 'label' => \App\Enums\PaymentMethod::from($m)->label(), 'book_id' => isset($stored['method_books'][$m]) ? (int) $stored['method_books'][$m] : null];
        }

        return [
            'methods' => $methods,
            'card_topup_book' => isset($stored['card_topup_book']) ? (int) $stored['card_topup_book'] : null,
            'insurer_deposit_book' => isset($stored['insurer_deposit_book']) ? (int) $stored['insurer_deposit_book'] : null,
            'stock_credit_account' => isset($stored['stock_credit_account']) ? (int) $stored['stock_credit_account'] : null,
            'defaults' => $settings->defaults(),
            'books' => $settings->books()->map($opt)->values(),
            'credit_accounts' => $settings->creditAccounts()->map($opt)->values(),
        ];
    }
}
