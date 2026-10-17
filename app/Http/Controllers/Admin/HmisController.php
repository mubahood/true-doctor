<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Hmis\HmisReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HMIS 105, 108 and 033b as the paper form (PDF) and as rows (CSV). */
class HmisController extends Controller
{
    public function export(Request $request, HmisReports $hmis, string $form, string $format)
    {
        abort_unless($request->user()?->can('reports.view'), 403);
        abort_unless(in_array($form, ['105', '108', '033b'], true) && in_array($format, ['pdf', 'csv'], true), 404);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) === 1 ? (string) $request->query('month') : now()->subMonthNoOverflow()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $to = $from->copy()->addMonth();
        $report = match ($form) {
            '108' => $hmis->ipd($from, $to),
            '033b' => $hmis->weekly(Carbon::parse((string) ($request->query('week') ?: now()->subWeek()->toDateString()))),
            default => $hmis->opd($from, $to),
        };
        $name = 'hmis-'.$form.'-'.($form === '033b' ? $report['week']['from'] : $month);

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.hmis.'.($form === '033b' ? 'weekly' : ($form === '108' ? 'ipd' : 'opd')), ['report' => $report, 'version' => config('hmis.version')])
                ->setPaper('a4', $form === '105' ? 'landscape' : 'portrait')
                ->stream($name.'.pdf');
        }

        return new StreamedResponse(function () use ($form, $report) {
            $out = fopen('php://output', 'w');
            foreach ($this->rows($form, $report) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"']);
    }

    /** @param array<string,mixed> $r @return list<list<string|int>> */
    private function rows(string $form, array $r): array
    {
        if ($form === '033b') {
            $rows = [['Condition', 'Cases', 'Deaths']];
            foreach ($r['conditions'] as $c) {
                $rows[] = [$c['label'], $c['cases'], $c['deaths']];
            }

            return $rows;
        }

        $head = ['Line'];
        foreach ($r['bands'] as $label) {
            $head[] = $label.' male';
            $head[] = $label.' female';
        }
        $head[] = 'Total';
        $rows = [$head];
        $line = function (string $label, array $grid) use ($r) {
            $row = [$label];
            $total = 0;
            foreach (array_keys($r['bands']) as $b) {
                $row[] = $grid[$b]['male'];
                $row[] = $grid[$b]['female'];
                $total += $grid[$b]['male'] + $grid[$b]['female'];
            }
            $row[] = $total;

            return $row;
        };

        if ($form === '105') {
            $rows[] = $line('New attendance', $r['attendance']['new']);
            $rows[] = $line('Re-attendance', $r['attendance']['re']);
            foreach ($r['diagnoses'] as $d) {
                $rows[] = $line($d['label'], $d['counts']);
            }
            $rows[] = ['All other diagnoses', $r['other']];
        } else {
            $rows[] = $line('Admissions', $r['admissions']);
            $rows[] = $line('Discharges', $r['discharges']);
            $rows[] = $line('Deaths', $r['deaths']);
            $rows[] = ['Absconded', $r['absconded']];
            $rows[] = ['Bed days', $r['bed_days']];
            foreach ($r['diagnoses'] as $d) {
                $rows[] = ['Discharge diagnosis: '.$d['label'], $d['total']];
            }
        }

        return $rows;
    }
}
