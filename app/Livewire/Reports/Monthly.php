<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithTable;
use App\Services\Reports\MonthlyReports;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monthly reports (docs/reports.md): income, expenses, accounts receivable and
 * patient records for a month or any range of days, as a detailed table or a
 * summary, each taken away as PDF, Excel or CSV.
 *
 * Thin: the figures are MonthlyReports'. Everything that picks the report is
 * in the URL, so a report is a link somebody can send. The summary is cached
 * for a minute per hospital and choice, as the reports page is.
 *
 * @property-read ReportPeriod $period
 * @property-read array<string,mixed> $summary
 */
#[Layout('layouts.admin')]
class Monthly extends Component
{
    use WithTable;

    #[Url(history: true)]
    public string $type = 'income';

    #[Url(history: true)]
    public string $view = 'summary';

    #[Url(history: true, except: '')]
    public string $month = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    /** Whether the From and To dates are showing. */
    public bool $custom = false;

    public function mount(): void
    {
        $this->authorizeView();
        $this->type = MonthlyReports::type($this->type);
        $this->view = MonthlyReports::view($this->view);
        $this->custom = $this->from !== '' && $this->to !== '';
        if ($this->month === '' && ! $this->custom) {
            $this->month = $this->period->month ?? '';
        }
        $this->perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : 20;
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->can('reports.view'), 403);
        MonthlyReports::requireHospital();
    }

    protected function resetsPage(): array
    {
        return ['type', 'view', 'month', 'from', 'to'];
    }

    public function updatedType(): void
    {
        $this->type = MonthlyReports::type($this->type);
    }

    public function updatedView(): void
    {
        $this->view = MonthlyReports::view($this->view);
    }

    /** The date a range edit is replacing, to put back if it is cleared. */
    private string $was = '';

    public function updatingFrom(): void
    {
        $this->was = $this->from;
    }

    public function updatingTo(): void
    {
        $this->was = $this->to;
    }

    /** A cleared date keeps the range it was part of, rather than falling back to this month. */
    public function updatedFrom(): void
    {
        if ($this->from === '') {
            $this->from = $this->was !== '' ? $this->was : $this->to;
        }
    }

    public function updatedTo(): void
    {
        if ($this->to === '') {
            $this->to = $this->was !== '' ? $this->was : $this->from;
        }
    }

    public function updatedMonth(): void
    {
        // Picking a month leaves the custom range.
        $this->reset(['from', 'to', 'custom']);
    }

    public function show(string $view): void
    {
        $this->view = MonthlyReports::view($view);
        $this->resetPage();
    }

    public function useCustom(): void
    {
        $this->custom = true;
        $this->from = $this->period->from;
        $this->to = $this->period->to;
        unset($this->period, $this->summary);
        $this->resetPage();
    }

    public function useMonth(): void
    {
        $this->reset(['from', 'to', 'custom']);
        $this->month = $this->month !== '' ? $this->month : now()->format('Y-m');
        unset($this->period, $this->summary);
        $this->resetPage();
    }

    #[Computed]
    public function period(): ReportPeriod
    {
        return app(MonthlyReports::class)->period($this->month, $this->custom ? $this->from : null, $this->custom ? $this->to : null);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function summary(): array
    {
        $reports = app(MonthlyReports::class);

        return $reports->summary($reports->report($this->type), $this->period);
    }

    /** The query string the export links carry. @return array<string,string> */
    public function exportQuery(): array
    {
        $p = $this->period;

        return $p->isMonth() ? ['month' => (string) $p->month] : ['from' => $p->from, 'to' => $p->to];
    }

    public function render(): View
    {
        $this->authorizeView();
        $reports = app(MonthlyReports::class);
        $report = $reports->report($this->type);

        $data = ['report' => $report, 'types' => MonthlyReports::LABELS, 'views' => MonthlyReports::VIEWS];
        if ($this->view === 'detail') {
            $data['rows'] = $reports->page($this->type, $this->period, $this->getPage(), $this->perPage);
            $data['totals'] = $reports->totals($report, $this->period);
        }

        return view('livewire.reports.monthly', $data)->title('Monthly reports');
    }
}
