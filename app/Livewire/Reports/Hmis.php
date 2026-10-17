<?php

namespace App\Livewire\Reports;

use App\Services\Hmis\HmisReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Ministry reports on screen (roadmap 04 §2): HMIS 105 and 108 for a
 * month, 033b for a week, each with what is missing and a link to fix it, a
 * PDF laid out like the paper form and a CSV.
 */
#[Layout('layouts.admin')]
class Hmis extends Component
{
    #[Url(history: true)]
    public string $form = '105';

    #[Url(history: true)]
    public string $month = '';

    #[Url(history: true)]
    public string $week = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('reports.view'), 403);
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : now()->subMonthNoOverflow()->format('Y-m');
        $this->week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1 ? $this->week : now()->subWeek()->startOfWeek()->toDateString();
        $this->form = in_array($this->form, ['105', '108', '033b'], true) ? $this->form : '105';
    }

    public function render(HmisReports $hmis): View
    {
        abort_unless(Auth::user()?->can('reports.view'), 403);
        $from = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $to = $from->copy()->addMonth();

        $report = match ($this->form) {
            '108' => $hmis->ipd($from, $to),
            '033b' => $hmis->weekly(Carbon::parse($this->week)),
            default => $hmis->opd($from, $to),
        };

        // The form's lines, each a grid of age band × sex.
        $rows = match ($this->form) {
            '033b' => [],
            '108' => ['Admissions' => $report['admissions'], 'Discharges' => $report['discharges'], 'Deaths' => $report['deaths']],
            default => ['New attendance' => $report['attendance']['new'], 'Re-attendance' => $report['attendance']['re']]
                + collect($report['diagnoses'])->mapWithKeys(fn ($d) => [$d['label'] => $d['counts']])->all(),
        };

        return view('livewire.reports.hmis', ['report' => $report, 'rows' => $rows])->title('Ministry reports (HMIS)');
    }
}
