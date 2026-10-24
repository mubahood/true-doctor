<?php

namespace App\Livewire\Reports;

use App\Services\Hmis\HmisReports;
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
        HmisReports::requireHospital();
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : HmisReports::defaultMonth();
        $this->week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1 ? $this->week : HmisReports::defaultWeek();
        $this->form = HmisReports::form($this->form);
    }

    public function render(HmisReports $hmis): View
    {
        abort_unless(Auth::user()?->can('reports.view'), 403);
        HmisReports::requireHospital();
        $report = $hmis->forForm($this->form, $this->month, $this->week);

        // The form's lines, each a grid of age band × sex (the API reads the same).
        return view('livewire.reports.hmis', [
            'report' => $report,
            'rows' => $hmis->lines($this->form, $report),
            'forms' => HmisReports::FORMS,
        ])->title('Ministry reports (HMIS)');
    }
}
