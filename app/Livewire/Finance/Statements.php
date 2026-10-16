<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\KeepsBooks;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ReportRange;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The financial statements (docs/finance.md): income statement, balance
 * sheet, trial balance and cash flow, over a period the reader chooses, read
 * live off the journal. Each one can be compared with the period before (or
 * the same period a year earlier), downloaded as a spreadsheet, or taken
 * away as a PDF with the hospital's letterhead.
 *
 * The balance sheet and trial balance are "as at" the period's last day;
 * the income statement and cash flow cover the whole period.
 */
#[Layout('layouts.admin')]
class Statements extends Component
{
    use KeepsBooks;

    public const REPORTS = [
        'income' => ['Income statement', 'fa-chart-line'],
        'balance' => ['Balance sheet', 'fa-scale-balanced'],
        'trial' => ['Trial balance', 'fa-list-check'],
        'cashflow' => ['Cash flow', 'fa-money-bill-transfer'],
    ];

    #[Url(history: true, except: 'income')]
    public string $report = 'income';

    #[Url(history: true, except: '')]
    public string $range = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    /** none | previous | year */
    #[Url(as: 'vs', history: true, except: 'none')]
    public string $compare = 'none';

    public function mount(): void
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        if (! array_key_exists($this->report, self::REPORTS)) {
            $this->report = 'income';
        }
        if ($this->range !== '' && array_key_exists($this->range, ReportRange::presets())) {
            [$this->from, $this->to] = ReportRange::forPreset($this->range);
        }
        if ($this->from === '' || $this->to === '') {
            [$this->from, $this->to] = ReportRange::forPreset('month');
        }
        $this->range = '';
    }

    public function show(string $report): void
    {
        $this->report = array_key_exists($report, self::REPORTS) ? $report : 'income';
    }

    public function preset(string $key): void
    {
        [$this->from, $this->to] = ReportRange::forPreset($key);
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function period(): array
    {
        [$from, $to] = ReportRange::read($this->from, $this->to);

        return [Carbon::parse($from), Carbon::parse($to)];
    }

    /** The period compared against, if any. @return array{0:Carbon,1:Carbon}|null */
    private function comparison(): ?array
    {
        [$from, $to] = $this->period();

        $range = app(FinancialStatements::class)->comparisonRange($this->compare, $from, $to);

        return $range[0] === null ? null : $range;
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function data(): array
    {
        [$from, $to] = $this->period();

        return app(FinancialStatements::class)->report($this->report, $from, $to, $this->compare);
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        [$from, $to] = $this->period();
        $cmp = $this->comparison();

        return view('livewire.finance.statements', [
            'reports' => self::REPORTS,
            'presets' => ReportRange::presets(),
            'periodLabel' => in_array($this->report, ['balance', 'trial'], true)
                ? 'As at '.$to->format('d M Y')
                : $from->format('d M Y').' – '.$to->format('d M Y'),
            'compareLabel' => $cmp ? $cmp[0]->format('d M Y').' – '.$cmp[1]->format('d M Y') : null,
            'exportQuery' => ['report' => $this->report, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'vs' => $this->compare],
        ])->title('Financial statements');
    }
}
