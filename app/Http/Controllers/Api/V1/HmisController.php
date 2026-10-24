<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Hmis\HmisReports;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Ministry reports (HMIS 105, 108 and 033b) as data for the app
 * (reports.view, docs/api/reports.md): the web page's figures, read through
 * the same HmisReports::forForm(), so the phone, the page, the PDF and the
 * CSV count the same days. The files are the web's own controller
 * (/reports/hmis/{form}.{pdf|csv}).
 */
class HmisController extends Controller
{
    /** How many rows of missing data an answer carries; the page shows as many. */
    private const MISSING_SHOWN = 100;

    /** The days the notifiable-disease alert looks back, as the dashboard's insight does. */
    private const ALERT_DAYS = 7;

    /** GET reports/hmis : the forms, where each opens, and the notifiable alert. */
    public function index(Request $request, HmisReports $hmis): JsonResponse
    {
        $this->allow($request);

        $cases = $hmis->notifiableSince(now()->subDays(self::ALERT_DAYS));
        arsort($cases);

        return ApiResponse::success([
            'version' => (string) config('hmis.version'),
            'forms' => self::forms(),
            'default_month' => HmisReports::defaultMonth(),
            'default_week' => HmisReports::defaultWeek(),
            'notifiable' => [
                'days' => self::ALERT_DAYS,
                'total' => array_sum($cases),
                'cases' => array_map(fn ($label, $count) => ['label' => (string) $label, 'count' => (int) $count], array_keys($cases), array_values($cases)),
            ],
        ]);
    }

    /** GET reports/hmis/{form}?month=YYYY-MM&week=YYYY-MM-DD : one form's figures. */
    public function show(Request $request, HmisReports $hmis, string $form): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $form = HmisReports::form($form);
        $month = $data['month'] ?? HmisReports::defaultMonth();
        $week = $data['week'] ?? HmisReports::defaultWeek();
        $report = $hmis->forForm($form, $month, $week);
        $weekly = $form === '033b';

        $bands = [];
        foreach ($weekly ? [] : (array) $report['bands'] as $key => $label) {
            $bands[] = ['key' => (string) $key, 'label' => (string) $label];
        }

        $lines = [];
        foreach ($hmis->lines($form, $report) as $label => $grid) {
            $total = 0;
            foreach ($grid as $sexes) {
                $total += (int) $sexes['male'] + (int) $sexes['female'];
            }
            $lines[] = ['label' => (string) $label, 'counts' => $grid, 'total' => $total];
        }

        $missing = $weekly ? [] : (array) $report['missing'];
        $query = $weekly ? ['week' => $report['week']['from']] : ['month' => $month];

        return ApiResponse::success([
            'form' => $form,
            'label' => HmisReports::FORMS[$form]['label'],
            'version' => (string) config('hmis.version'),
            'period' => $weekly ? [
                'kind' => 'week',
                'month' => null,
                'week' => $report['week']['from'],
                'from' => $report['week']['from'],
                'to' => $report['week']['to'],
                'number' => $report['week']['number'],
                'year' => $report['week']['year'],
                'label' => "Week {$report['week']['number']} of {$report['week']['year']}: {$report['week']['from']} to {$report['week']['to']}",
            ] : [
                'kind' => 'month',
                'month' => $month,
                'week' => null,
                'from' => $report['period']['from'],
                'to' => $report['period']['to'],
                'label' => Carbon::createFromFormat('Y-m-d', $report['period']['from'])->format('F Y'),
            ],
            'bands' => $bands,
            'lines' => $lines,
            'notes' => $this->notes($form, $report),
            'conditions' => $weekly
                ? array_map(fn ($key, $c) => ['key' => (string) $key, 'label' => $c['label'], 'cases' => $c['cases'], 'deaths' => $c['deaths']], array_keys($report['conditions']), array_values($report['conditions']))
                : [],
            'missing' => array_slice($missing, 0, self::MISSING_SHOWN),
            'missing_total' => count($missing),
            'files' => [
                'pdf' => route('api.reports.hmis.export', ['form' => $form, 'format' => 'pdf'] + $query),
                'csv' => route('api.reports.hmis.export', ['form' => $form, 'format' => 'csv'] + $query),
            ],
        ]);
    }

    // ── Inside ───────────────────────────────────────────────────────────

    /** As the web page: reports.view, about one hospital. */
    private function allow(Request $request): void
    {
        abort_unless($request->user()?->can('reports.view'), 403);
        HmisReports::requireHospital();
    }

    /** @return list<array{key:string,label:string,period:string}> */
    private static function forms(): array
    {
        $out = [];
        foreach (HmisReports::FORMS as $key => $f) {
            $out[] = ['key' => (string) $key, 'label' => $f['label'], 'period' => $f['period']];
        }

        return $out;
    }

    /**
     * The figures the page writes under the grid: the other diagnoses and the
     * visits for 105; bed days, absconded and the discharge diagnoses for 108.
     *
     * @param  array<string,mixed>  $report
     * @return list<array{label:string,value:int}>
     */
    private function notes(string $form, array $report): array
    {
        return match ($form) {
            '033b' => [],
            '108' => [
                ['label' => 'Bed days', 'value' => (int) $report['bed_days']],
                ['label' => 'Absconded', 'value' => (int) $report['absconded']],
                ...array_map(fn (array $d) => ['label' => 'Discharge diagnosis: '.$d['label'], 'value' => (int) $d['total']], array_values($report['diagnoses'])),
            ],
            default => [
                ['label' => 'All other diagnoses', 'value' => (int) $report['other']],
                ['label' => 'Visits this month', 'value' => (int) $report['total_visits']],
            ],
        };
    }
}
