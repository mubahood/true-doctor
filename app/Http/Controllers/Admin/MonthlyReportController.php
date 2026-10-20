<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\MonthlyReport;
use App\Services\Reports\MonthlyReports;
use App\Services\Reports\ReportPeriod;
use App\Support\HospitalSettings;
use App\Support\Imports\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A monthly report taken away (docs/reports.md, Monthly reports): a PDF on the
 * letterhead that opens in its own tab, an Excel workbook, or a CSV. The same
 * rows and figures as the page, read fresh from MonthlyReports. Used by the
 * web and, behind a token, by the app.
 */
class MonthlyReportController extends Controller
{
    /** The PDF, inline: it opens in its own tab like every generated document. */
    public function document(Request $request, MonthlyReports $reports, string $type, string $view): Response
    {
        return $this->export($request, $reports, $type, $view, 'pdf');
    }

    public function export(Request $request, MonthlyReports $reports, string $type, string $view, string $format): Response
    {
        abort_unless($request->user()?->can('reports.view'), 403);
        abort_unless(isset(MonthlyReports::TYPES[$type], MonthlyReports::VIEWS[$view]) && in_array($format, ['pdf', 'xlsx', 'csv'], true), 404);
        MonthlyReports::requireHospital();

        $period = $reports->period($request->query('month'), $request->query('from'), $request->query('to'));
        $report = $reports->report($type, $request->user());
        $name = str(app(HospitalSettings::class)->hospital()->name ?? 'report')->slug()->value()
            .'-'.$type.'-'.($view === 'detail' ? 'detailed' : 'summary').'-'.$period->slug();

        return match ($format) {
            'pdf' => $this->pdf($request, $report, $type, $view, $period, $name),
            'xlsx' => $this->xlsx($report, $view, $period, $name),
            default => $this->csv($report, $view, $period, $name),
        };
    }

    private function pdf(Request $request, MonthlyReport $report, string $type, string $view, ReportPeriod $period, string $name): Response
    {
        $data = [
            'report' => $report,
            'type' => $type,
            'view' => $view,
            'period' => $period,
            'generatedBy' => $request->user()->name,
            'generatedAt' => Carbon::now(),
        ];

        if ($view === 'detail') {
            $count = $report->count($period);
            // The database reads only the lines the PDF will print.
            $rows = iterator_to_array($report->rows($period, 0, MonthlyReports::PDF_ROW_LIMIT), false);
            $data += ['rows' => $rows, 'count' => $count, 'totals' => $report->totals($period)];
        } else {
            $data['summary'] = $report->summary($period);
        }

        // A table of a dozen columns needs the page on its side; a summary reads upright.
        return Pdf::loadView('pdf.monthly-report', $data)
            ->setPaper('a4', $view === 'detail' ? 'landscape' : 'portrait')
            ->stream($name.'.pdf');
    }

    private function xlsx(MonthlyReport $report, string $view, ReportPeriod $period, string $name): Response
    {
        $sheets = [];
        if ($view === 'detail') {
            $columns = $report->columns();
            $sheets[] = [
                'name' => 'Detailed table',
                'rows' => $this->detailRows($report, $period, $columns),
                'types' => array_column($columns, 'type'),
            ];
        } else {
            $summary = $report->summary($period);
            // Each figure in the column for its kind, so money is formatted as
            // money, a count as a count, and a change as a percentage.
            $sheets[] = [
                'name' => 'Figures',
                'rows' => array_merge([['Figure', 'Amount', 'Count', 'Change', 'Note']], array_map(fn ($f) => [
                    $f['label'],
                    $f['type'] === 'money' ? MonthlyReports::plain($f['value'], 'money') : '',
                    $f['type'] === 'int' ? MonthlyReports::plain($f['value'], 'int') : '',
                    $f['type'] === 'percent' ? MonthlyReports::display($f['value'], 'percent') : '',
                    $f['note'] ?? '',
                ], $summary['figures'])),
                'types' => ['text', 'money', 'int', 'text', 'text'],
            ];
            foreach ($summary['tables'] as $table) {
                $sheets[] = [
                    'name' => mb_substr($table['title'], 0, 31),
                    'rows' => $this->tableRows($table),
                    'types' => array_column($table['columns'], 'type'),
                ];
            }
        }

        return response(XlsxWriter::make($sheets), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
        ]);
    }

    private function csv(MonthlyReport $report, string $view, ReportPeriod $period, string $name): StreamedResponse
    {
        return new StreamedResponse(function () use ($report, $view, $period) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // so a spreadsheet reads the names as UTF-8

            if ($view === 'detail') {
                foreach ($this->detailRows($report, $period, $report->columns()) as $row) {
                    fputcsv($out, array_map(MonthlyReports::csvSafe(...), $row));
                }
            } else {
                $summary = $report->summary($period);
                fputcsv($out, [$report->title(), $period->label()]);
                foreach ($summary['figures'] as $f) {
                    fputcsv($out, array_map(MonthlyReports::csvSafe(...), [$f['label'], MonthlyReports::plain($f['value'], $f['type']), $f['note'] ?? '']));
                }
                foreach ($summary['tables'] as $table) {
                    fputcsv($out, []);
                    fputcsv($out, [$table['title']]);
                    foreach ($this->tableRows($table) as $row) {
                        fputcsv($out, array_map(MonthlyReports::csvSafe(...), $row));
                    }
                }
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"']);
    }

    /**
     * The heading, every row as it is read, then the totals.
     *
     * @param  list<array{key:string,label:string,type:string}>  $columns
     * @return \Generator<int, list<string>>
     */
    private function detailRows(MonthlyReport $report, ReportPeriod $period, array $columns): \Generator
    {
        yield array_column($columns, 'label');
        foreach ($report->rows($period) as $row) {
            yield array_map(fn ($c) => MonthlyReports::plain($row[$c['key']] ?? null, $c['type']), $columns);
        }

        // A total sits under the table's last money column; a count beside its label.
        $moneyAt = max(array_keys(array_filter(array_column($columns, 'type'), fn ($t) => $t === 'money')) ?: [count($columns) - 1]);
        $blank = array_fill(0, count($columns), '');
        yield $blank;
        foreach ($report->totals($period) as $t) {
            $line = $blank;
            $line[0] = $t['label'];
            $line[$t['type'] === 'money' ? $moneyAt : 1] = MonthlyReports::plain($t['value'], $t['type']);
            yield $line;
        }
    }

    /**
     * @param  array{columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,string|int|null>>,total?:array<string,string|int|null>|null}  $table
     * @return list<list<string>>
     */
    private function tableRows(array $table): array
    {
        $rows = [array_column($table['columns'], 'label')];
        foreach ([...$table['rows'], ...(isset($table['total']) ? [$table['total']] : [])] as $row) {
            $rows[] = array_map(fn ($c) => MonthlyReports::plain($row[$c['key']] ?? null, $c['type']), $table['columns']);
        }

        return $rows;
    }
}
