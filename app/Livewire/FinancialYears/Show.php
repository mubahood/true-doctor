<?php

namespace App\Livewire\FinancialYears;

use App\Livewire\Concerns\ManagesFinancialYears;
use App\Models\FinancialYear;
use App\Services\FinancialYearService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-period report (Detail shape, plan §4.4) — replaces
 * admin/financial-years/show.blade.php and FinancialYearController@show.
 *
 * Read-only except for the two period transitions, which are the
 * ManagesFinancialYears trait shared verbatim with FinancialYears\Index: the
 * roll-up and the "already closed" invariant stay in FinancialYearService.
 *
 * @property-read FinancialYear $year
 * @property-read array{payments_total:string,invoices_total:string,outstanding:string,by_method:array<string,string>,invoice_count:int,payment_count:int} $report
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests, ManagesFinancialYears;

    #[Locked]
    public int $yearId;

    public function mount(int|string $financialYear): void
    {
        // Tenant-scoped resolution: another hospital's period is a 404, not a 403.
        $model = FinancialYear::findOrFail($financialYear);
        $this->authorize('view', $model);

        $this->yearId = $model->id;
    }

    #[Computed]
    public function year(): FinancialYear
    {
        return FinancialYear::with('closedBy')->findOrFail($this->yearId);
    }

    /**
     * @return array{payments_total:string,invoices_total:string,outstanding:string,by_method:array<string,string>,invoice_count:int,payment_count:int}
     */
    #[Computed]
    public function report(): array
    {
        return app(FinancialYearService::class)->report($this->year);
    }

    /** A close/reopen changes the badge and (via assertPostingAllowed) nothing else. */
    protected function afterPeriodTransition(): void
    {
        unset($this->year, $this->report);
    }

    public function render()
    {
        $year = $this->year;
        $this->authorize('view', $year);

        return view('livewire.financial-years.show', [
            'year' => $year,
            'report' => $this->report,
            'methodLabels' => \App\Enums\PaymentMethod::options(),
        ])->title($year->name);
    }
}
