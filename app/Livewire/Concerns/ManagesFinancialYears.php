<?php

namespace App\Livewire\Concerns;

use App\Exceptions\FinancialYearException;
use App\Models\FinancialYear;
use App\Services\FinancialYearService;

/**
 * Close / reopen an accounting period. Shared verbatim by the finance index
 * (App\Livewire\FinancialYears\Index) and the per-period report
 * (App\Livewire\FinancialYears\Show) so the two surfaces can never drift.
 *
 * The transition itself and the "already closed" invariant live in
 * FinancialYearService; the trait only authorizes, reports the service's domain
 * exception as a toast and lets the host refresh whatever it caches.
 *
 * Requires AuthorizesRequests on the host component.
 */
trait ManagesFinancialYears
{
    public function close(int $year, FinancialYearService $service): void
    {
        $this->authorize('manage', FinancialYear::class);
        $model = FinancialYear::findOrFail($year);

        try {
            $service->close($model, auth()->id());
        } catch (FinancialYearException|\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->afterPeriodTransition();
        $this->dispatch('toast', message: 'Period closed.', type: 'success');
    }

    public function reopen(int $year, FinancialYearService $service): void
    {
        $this->authorize('manage', FinancialYear::class);
        $service->reopen(FinancialYear::findOrFail($year));

        $this->afterPeriodTransition();
        $this->dispatch('toast', message: 'Period reopened.', type: 'success');
    }

    /** Hook: a host that caches the period (or its report) invalidates it here. */
    protected function afterPeriodTransition(): void {}
}
