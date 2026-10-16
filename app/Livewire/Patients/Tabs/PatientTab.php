<?php

namespace App\Livewire\Patients\Tabs;

use App\Livewire\Patients\Panels\InteractsWithPatient;
use App\Services\PatientSummaryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;

/**
 * What every tab of the patient record shares: the patient (re-resolved through
 * the tenant scope, never trusted from the client), the sections this person may
 * see, and the one service that knows the facts.
 *
 * A tab authorises itself in mount() AND re-checks its section on every render:
 * Livewire components are addressable on their own, so hiding a tab button is
 * not a permission.
 *
 * @property-read list<string> $sections
 */
trait PatientTab
{
    use AuthorizesRequests, InteractsWithPatient;

    /** @return list<string> */
    #[Computed]
    public function sections(): array
    {
        return PatientSummaryService::sectionsFor(auth()->user());
    }

    /** Refuse the tab to someone who may not see its section. */
    protected function requireSection(string $section): void
    {
        $this->authorize('view', $this->patient);
        abort_unless(in_array($section, $this->sections, true), 403);
    }

    protected function summaryService(): PatientSummaryService
    {
        return app(PatientSummaryService::class);
    }
}
