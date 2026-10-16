<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Finance\FinancialStatements;
use App\Support\ReportRange;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The books, taken away (docs/finance.md): any statement as a PDF on the
 * hospital's letterhead, and the statements, an account's ledger or the
 * cashbook as a spreadsheet for an accountant who works in one.
 *
 * Reads FinancialStatements directly — the same service the screens read —
 * so a download can never disagree with the page it was taken from. Money in
 * a CSV is a plain decimal with no currency symbol or separators, because a
 * spreadsheet that has to un-format numbers before it can add them up is a
 * spreadsheet nobody will use.
 */
class FinanceExportController extends Controller
{
    public function csv(Request $request, FinancialStatements $statements): StreamedResponse
    {
        abort_unless($request->user()?->can('finance.view'), 403);
        abort_if(app(\App\Support\CurrentHospital::class)->id() === null, 404);

        [$from, $to] = $this->range($request);
        $what = (string) $request->query('what', 'statement');

        [$name, $rows] = match ($what) {
            'ledger' => $this->ledger($request, $statements, $from, $to),
            'transactions' => $this->transactions($request, $from, $to),
            default => $this->statement((string) $request->query('report', 'income'), $statements, $from, $to, (string) $request->query('vs', 'none')),
        };

        $filename = str($name)->slug().'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the dashes and accents as UTF-8
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request, FinancialStatements $statements): Response
    {
        abort_unless($request->user()?->can('finance.view'), 403);
        abort_if(app(\App\Support\CurrentHospital::class)->id() === null, 404);

        [$from, $to] = $this->range($request);
        $report = (string) $request->query('report', 'income');
        $report = in_array($report, ['income', 'balance', 'trial', 'cashflow'], true) ? $report : 'income';
        [$cFrom, $cTo] = $this->comparison((string) $request->query('vs', 'none'), $from, $to);

        $data = $statements->report($report, $from, $to, (string) $request->query('vs', 'none'));

        $title = \App\Livewire\Finance\Statements::REPORTS[$report][0];
        $period = in_array($report, ['balance', 'trial'], true) ? 'As at '.$to->format('d M Y') : $from->format('d M Y').' — '.$to->format('d M Y');

        return Pdf::loadView('pdf.finance-statement', [
            'report' => $report,
            'title' => $title,
            'period' => $period,
            'compare' => $cFrom ? $cFrom->format('d M Y').' — '.$cTo->format('d M Y') : null,
            'd' => $data,
            'generatedAt' => Carbon::now(),
            'generatedBy' => $request->user()->name,
        ])->setPaper('a4')->stream(str($title)->slug().'-'.$to->format('Ymd').'.pdf');
    }

    /** @return array{0:string,1:list<list<string|null>>} */
    private function statement(string $report, FinancialStatements $statements, Carbon $from, Carbon $to, string $vs): array
    {
        $rows = [];

        switch ($report) {
            case 'balance':
                $d = $statements->balanceSheet($to);
                $rows[] = ['Balance sheet as at', $to->toDateString()];
                foreach (['Current assets' => $d['current_assets'], 'Fixed assets' => $d['fixed_assets'], 'Current liabilities' => $d['current_liabilities'], 'Long-term liabilities' => $d['long_term_liabilities'], 'Equity' => $d['equity']] as $title => $section) {
                    $rows[] = [$title];
                    foreach ($section['rows'] as $r) {
                        $rows[] = [$r['account']->code, $r['account']->name, $r['amount']];
                    }
                    $rows[] = ['', 'Total '.strtolower($title), $section['total']];
                }
                $rows[] = ['', 'Profit kept from earlier years', $d['earlier_profit']];
                $rows[] = ['', 'Profit this year', $d['year_profit']];
                $rows[] = ['', 'Total assets', $d['assets']];
                $rows[] = ['', 'Liabilities and equity', $d['liabilities_and_equity']];

                return ['Balance sheet', $rows];

            case 'trial':
                $d = $statements->trialBalance($to);
                $rows[] = ['Code', 'Account', 'Debit', 'Credit'];
                foreach ($d['rows'] as $r) {
                    $rows[] = [$r['account']->code, $r['account']->name, $r['debit'], $r['credit']];
                }
                $rows[] = ['', 'Totals', $d['debit'], $d['credit']];

                return ['Trial balance', $rows];

            case 'cashflow':
                $d = $statements->cashFlow($from, $to);
                $rows[] = ['Money in'];
                foreach ($d['inflows'] as $r) {
                    $rows[] = ['', $r['label'], $r['amount']];
                }
                $rows[] = ['Money out'];
                foreach ($d['outflows'] as $r) {
                    $rows[] = ['', $r['label'], $r['amount']];
                }
                $rows[] = ['Book', 'Opening', 'In', 'Out', 'Closing'];
                foreach ($d['books'] as $b) {
                    $rows[] = [$b['account']->name, $b['opening'], $b['in'], $b['out'], $b['closing']];
                }

                return ['Cash flow', $rows];

            default:
                [$cFrom, $cTo] = $this->comparison($vs, $from, $to);
                $d = $statements->incomeStatement($from, $to, $cFrom, $cTo);
                $rows[] = ['Code', 'Account', $from->toDateString().' to '.$to->toDateString(), $cFrom ? $cFrom->toDateString().' to '.$cTo->toDateString() : null];
                foreach (['Income' => $d['revenue'], 'Discounts' => $d['discounts'], 'Cost of sales' => $d['cost_of_sales'], 'Expenses' => $d['expenses'], 'Other income' => $d['other_income']] as $title => $section) {
                    $rows[] = [$title];
                    foreach ($section['rows'] as $r) {
                        $rows[] = [$r['account']->code, $r['account']->name, $r['amount'], $r['compare']];
                    }
                }
                foreach (['net_revenue' => 'Net income', 'gross_profit' => 'Gross profit', 'operating_profit' => 'Operating profit', 'net_profit' => 'Net profit'] as $k => $label) {
                    $rows[] = ['', $label, $d['totals'][$k], $d['compare'][$k] ?? null];
                }

                return ['Income statement', $rows];
        }
    }

    /** @return array{0:string,1:list<list<string|null>>} */
    private function ledger(Request $request, FinancialStatements $statements, Carbon $from, Carbon $to): array
    {
        $account = LedgerAccount::query()->findOrFail((int) $request->query('account'));
        $st = $statements->statement($account, $from, $to);

        $rows = [['Date', 'Entry', 'Description', 'Party', 'Reference', 'Debit', 'Credit', 'Balance']];
        $rows[] = [$from->toDateString(), '', 'Opening balance', '', '', '', '', $st['opening']];
        foreach ($st['lines'] as $r) {
            $l = $r['line'];
            $rows[] = [$l->date->toDateString(), $l->entry->entry_no, $l->memo ?: $l->entry->memo, $l->entry->party, $l->entry->reference, $l->debit, $l->credit, $r['balance']];
        }
        $rows[] = [$to->toDateString(), '', 'Closing balance', '', '', $st['debit'], $st['credit'], $st['closing']];

        return [$account->code.' '.$account->name, $rows];
    }

    /** @return array{0:string,1:list<list<string|null>>} */
    private function transactions(Request $request, Carbon $from, Carbon $to): array
    {
        $books = LedgerAccount::query()->books()->pluck('id')->all();
        $book = (int) $request->query('book', 0);
        $search = trim((string) $request->query('q', ''));

        $rows = [['Date', 'Entry', 'Description', 'Party', 'Reference', 'Book', 'Kind', 'In', 'Out']];

        JournalLine::query()->with(['account', 'entry'])
            ->whereIn('ledger_account_id', $book > 0 ? [$book] : $books)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('dir') === 'in', fn ($q) => $q->where('debit', '>', 0))
            ->when($request->query('dir') === 'out', fn ($q) => $q->where('credit', '>', 0))
            ->when(filled($request->query('kind')), fn ($q) => $q->whereHas('entry', fn ($e) => $e->where('kind', (string) $request->query('kind'))))
            ->when($search !== '', fn ($q) => $q->whereHas('entry', fn ($e) => $e->where('memo', 'like', "%{$search}%")->orWhere('party', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")))
            ->orderBy('date')->orderBy('id')
            ->chunk(500, function ($lines) use (&$rows) {
                foreach ($lines as $l) {
                    $rows[] = [
                        $l->date->toDateString(), $l->entry->entry_no, $l->entry->memo, $l->entry->party, $l->entry->reference,
                        $l->account->name, $l->entry->kind->label(),
                        (float) $l->debit > 0 ? (string) $l->debit : '', (float) $l->credit > 0 ? (string) $l->credit : '',
                    ];
                }
            });

        return ['Transactions', $rows];
    }

    /**
     * The range asked for. An open-ended cashbook export (no dates) covers
     * everything the books hold, which is what "download all of it" means.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(Request $request): array
    {
        if ($request->query('what') === 'transactions' && blank($request->query('from')) && blank($request->query('to'))) {
            return [Carbon::create(2000, 1, 1), Carbon::now()->addYear()];
        }

        [$from, $to] = ReportRange::read($request->query('from'), $request->query('to'));

        return [Carbon::parse($from), Carbon::parse($to)];
    }

    /** @return array{0:?Carbon,1:?Carbon} */
    private function comparison(string $vs, Carbon $from, Carbon $to): array
    {
        return app(FinancialStatements::class)->comparisonRange($vs, $from, $to);
    }
}
