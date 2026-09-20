<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\VisitStage;
use App\Services\VisitService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * The next step, at the foot of the section that earns it.
 *
 * A gate belongs to the work that opens it: the one out of Ongoing is opened
 * by finishing the orders, so it belongs under the orders; the one out of
 * Billing is opened by raising an invoice, so it belongs under the bill.
 * Making someone scroll back to the summary to press a button about what they
 * have just finished doing is how a screen makes people feel it was written by
 * somebody who never used it.
 *
 * The host says which stage it owns; everything else is the visit's own gate
 * (docs/visits.md), so there is exactly one rule and one place it lives.
 */
trait ShowsTheNextStep
{
    /** The stage whose gate this section is responsible for opening. */
    abstract protected function ownsStage(): VisitStage;

    /**
     * The gate, but only when it is this section's to show.
     *
     * @return array{stage:VisitStage,next:?VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool}|null
     */
    #[Computed]
    public function nextStep(): ?array
    {
        $visit = $this->visit;

        if (! $visit->isOpen() || $visit->stage !== $this->ownsStage()) {
            return null;
        }

        if (Auth::user()?->can('manage', $visit) !== true) {
            return null;
        }

        $gate = app(VisitService::class)->readiness($visit);

        return $gate['next'] === null ? null : $gate;
    }

    /** Press it. There is only ever one next stage, so this takes no target. */
    public function advanceVisit(VisitService $service): void
    {
        $visit = $this->visit;
        $this->authorize('manage', $visit);

        try {
            $service->advance($visit, Auth::id());
        } catch (\App\Exceptions\InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->visit, $this->nextStep);

        $this->dispatch('toast', message: 'Now at '.$this->visit->stage->label().'.', type: 'success');
        $this->dispatch('visit-updated');
    }
}
