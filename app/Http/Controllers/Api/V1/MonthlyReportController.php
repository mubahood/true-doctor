<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\MonthlyReport;
use App\Services\Reports\MonthlyReports;
use App\Services\Reports\ReportPeriod;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web's Monthly reports as data (reports.view, docs/api/reports.md): the
 * same MonthlyReports figures for the same period rules. `view=summary` gives
 * the headline figures and the summary tables; `view=detail` one page of the
 * detailed table with its totals. The files are the web's own
 * (/reports/monthly/{type}/{view}.{pdf|xlsx|csv}).
 */
class MonthlyReportController extends Controller
{
    public function index(Request $request, MonthlyReports $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        MonthlyReports::requireHospital();

        $data = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(MonthlyReports::TYPES))],
            'view' => ['nullable', 'in:detail,summary'],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $type = MonthlyReports::type($data['type'] ?? null);
        $view = MonthlyReports::view($data['view'] ?? null);
        $period = $reports->period($data['month'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        $report = $reports->report($type, $request->user());

        $body = [
            'type' => $type,
            'view' => $view,
            'title' => $report->title(),
            'period' => $period->toArray(),
            'clinician_label' => MonthlyReport::clinician(),
            'types' => self::options(MonthlyReports::LABELS),
            'views' => self::options(MonthlyReports::VIEWS),
            'files' => [
                'pdf' => route('api.reports.monthly.pdf', ['type' => $type, 'view' => $view] + self::query($period)),
                'xlsx' => route('api.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'xlsx'] + self::query($period)),
                'csv' => route('api.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'csv'] + self::query($period)),
            ],
        ];

        if ($view === 'summary') {
            return ApiResponse::success($body + $reports->summary($report, $period));
        }

        $page = $reports->page($type, $period, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50), $request->user());

        return ApiResponse::withMeta($body + [
            'columns' => $report->columns(),
            'rows' => $page->items(),
            'totals' => $reports->totals($report, $period),
        ], [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /** @param array<string,string> $labels @return list<array{key:string,label:string}> */
    private static function options(array $labels): array
    {
        return array_map(fn ($k, $l) => ['key' => (string) $k, 'label' => $l], array_keys($labels), array_values($labels));
    }

    /** @return array<string,string> */
    private static function query(ReportPeriod $period): array
    {
        return $period->isMonth() ? ['month' => (string) $period->month] : ['from' => $period->from, 'to' => $period->to];
    }
}
