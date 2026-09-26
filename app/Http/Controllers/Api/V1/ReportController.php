<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The web's reports page as data (reports.view): the same ReportService
 * sections for the same period rules (ReportRange), and the same 60-second
 * cache per section, period and hospital. The PDF is the web's own
 * (/reports/pdf).
 */
class ReportController extends Controller
{
    private const TTL = 60;

    public function index(Request $request, ReportService $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);

        [$from, $to] = filled($request->query('preset'))
            ? ReportRange::forPreset((string) $request->query('preset'))
            : ReportRange::read($request->query('from'), $request->query('to'));
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $hospital = app(CurrentHospital::class)->id() ?? 'none';
        $cached = fn (string $section, \Closure $fn) => Cache::remember("reports:{$hospital}:{$section}:{$from}:{$to}", self::TTL, $fn);

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'preset' => ReportRange::presetOf($from, $to),
            'presets' => ReportRange::presets(),
            'revenue' => $cached('revenue', fn () => $reports->revenue($f, $t)),
            'outstanding' => $cached('outstanding', fn () => $reports->outstanding()),
            'valuation' => $cached('valuation', fn () => $reports->stockValuation()),
            'occupancy' => $cached('occupancy', fn () => $reports->occupancy()),
            'inpatient' => $cached('inpatient', fn () => $reports->inpatientNights($f, $t)),
            'productivity' => $cached('productivity', fn () => $reports->doctorProductivity($f, $t)),
            'service_revenue' => $cached('serviceRevenue', fn () => $reports->serviceRevenue($f, $t)),
            'demographics' => $cached('demographics', fn () => $reports->demographics()),
        ]);
    }
}
