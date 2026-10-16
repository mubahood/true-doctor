<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialStatementResource;
use App\Livewire\Finance\Statements;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ApiResponse;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The financial statements (web: admin.finance.statements), for a period, as
 * sections -> rows -> amounts. The PDF and CSV are the web's own downloads
 * (routes api.finance.statements.pdf / api.finance.export).
 */
class StatementController extends Controller
{
    use AnswersFinanceRequests;

    /**
     * finance.view. ?report=income|balance|trial|cashflow (default income), ?preset= (a
     * ReportRange preset) or ?from=&to= (default this month), ?vs=none|previous|year
     * (income statement only). Balance sheet and trial balance are "as at" `to`.
     */
    public function show(Request $request, FinancialStatements $statements): JsonResponse
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        $report = (string) $request->query('report', 'income');
        $report = array_key_exists($report, Statements::REPORTS) ? $report : 'income';

        $preset = (string) $request->query('preset', '');
        [$f, $t] = array_key_exists($preset, ReportRange::presets())
            ? ReportRange::forPreset($preset)
            : ReportRange::read($request->query('from'), $request->query('to'));
        $from = Carbon::parse($f);
        $to = Carbon::parse($t);

        $vs = (string) $request->query('vs', 'none');
        $vs = in_array($vs, ['none', 'previous', 'year'], true) ? $vs : 'none';
        [$cFrom, $cTo] = $statements->comparisonRange($vs, $from, $to);

        $data = $statements->report($report, $from, $to, $vs);

        return ApiResponse::success([
            'report' => $report,
            'reports' => collect(Statements::REPORTS)->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0]])->values(),
            'presets' => ReportRange::presets(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period_label' => in_array($report, ['balance', 'trial'], true) ? 'As at '.$to->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y'),
            'compare' => $report === 'income' && $cFrom !== null ? ['from' => $cFrom->toDateString(), 'to' => $cTo->toDateString(), 'vs' => $vs] : null,
            'statement' => (new FinancialStatementResource(['report' => $report, 'data' => $data]))->resolve(),
        ]);
    }
}
