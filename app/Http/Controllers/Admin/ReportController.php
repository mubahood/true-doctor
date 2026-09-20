<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\HospitalSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reports dashboard, as a document somebody can take to a meeting.
 *
 * A screen answers "how are we doing"; a board paper has to be handed over,
 * filed and read a month later by somebody who was not there. So the PDF
 * carries the same figures the screen shows, over the same range, with the
 * range and the moment it was taken printed on it — a page of numbers with no
 * dates on it is a page nobody can check.
 *
 * It reads the service directly rather than the Livewire component's cache:
 * a document is worth one fresh query, and a report that quietly came out of
 * a sixty-second cache is a report that might disagree with the screen beside
 * it for no reason anybody could see.
 */
class ReportController extends Controller
{
    public function pdf(Request $request, ReportService $reports): Response
    {
        abort_unless($request->user()?->can('reports.view'), 403);

        [$from, $to] = $this->range($request);

        $hospital = app(HospitalSettings::class)->hospital();

        $pdf = Pdf::loadView('pdf.report', [
            'hospital' => $hospital,
            'from' => $from,
            'to' => $to,
            'generatedAt' => Carbon::now(),
            'generatedBy' => $request->user()->name,
            'revenue' => $reports->revenue($from, $to),
            'outstanding' => $reports->outstanding(),
            'valuation' => $reports->stockValuation(),
            'occupancy' => $reports->occupancy(),
            'inpatient' => $reports->inpatientNights($from, $to),
            'productivity' => $reports->doctorProductivity($from, $to),
            'serviceRevenue' => $reports->serviceRevenue($from, $to),
            'demographics' => $reports->demographics(),
        ])->setPaper('a4');

        // Inline, not a download. Every generated document in this system is
        // read before it is kept, and sending a browser straight to a save
        // dialog for something somebody only wanted to look at is how a
        // downloads folder fills with `report (3).pdf`.
        return $pdf->stream($this->filename($hospital?->name, $from, $to));
    }

    /**
     * The range asked for, defaulting to this month.
     *
     * Bad input falls back rather than failing: this URL is shared, bookmarked
     * and edited by hand, and a 500 for a mistyped date helps nobody.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(Request $request): array
    {
        $from = $this->parse($request->query('from'), Carbon::now()->startOfMonth());
        $to = $this->parse($request->query('to'), Carbon::now()->endOfMonth());

        // Back to front is a typo, not a request for nothing.
        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    private function parse(mixed $value, Carbon $default): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function filename(?string $hospital, Carbon $from, Carbon $to): string
    {
        $slug = str($hospital ?? 'report')->slug()->value();

        return "{$slug}-report-{$from->toDateString()}-to-{$to->toDateString()}.pdf";
    }
}
