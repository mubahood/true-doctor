<?php

namespace App\Livewire\Visits;

use App\Enums\VisitStage;
use App\Exceptions\InvalidVisitTransitionException;
use App\Models\Visit;
use App\Services\VisitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The visit workspace — a Detail shape (plan §4.4, D1–D3): a read-only
 * summary column plus seven #[Lazy] child panels (vitals, clinical, charges,
 * lab, radiology, dispense, prescriptions) that each own their data, their
 * ability and their writes.
 *
 * Replaces admin/visits/show.blade.php (306 lines, 12 classic POST forms,
 * 92 inline styles, a <select> over every stock item) together with
 * VisitController, the medical-service controller, PrescriptionController,
 * DispensationController@store, InvoiceController@generate and the two
 * order-and-bill controller actions.
 *
 * Thin (house rule 4): mount/render authorize; the only action here advances the
 * pipeline through VisitService, which owns the state machine.
 *
 * @property-read Visit $visit
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $visitId;

    /** Reason captured alongside a cancellation (the only transition that takes one). */
    public ?string $cancelNote = null;

    public function mount(Visit $visit): void
    {
        $this->authorize('view', $visit);

        $this->visitId = $visit->id;
    }

    #[Computed]
    public function visit(): Visit
    {
        return Visit::with(['patient', 'doctor', 'department', 'history.changedBy'])
            ->findOrFail($this->visitId);
    }

    /** A panel changed something the header mirrors (status, doctor) — re-read. */
    #[On('visit-updated')]
    public function refreshVisit(): void
    {
        unset($this->visit);
    }

    /**
     * What this visit may do next — see docs/visits.md.
     *
     * @return array{stage:VisitStage,next:?VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool}
     */
    #[Computed]
    public function gate(): array
    {
        return app(VisitService::class)->readiness($this->visit);
    }

    /** Press the gate open. There is only ever one next stage, so no target. */
    public function advance(VisitService $service): void
    {
        $visit = $this->visit;
        $this->authorize('manage', $visit);

        try {
            $service->advance($visit, Auth::id());
        } catch (InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->visit, $this->gate);

        $this->dispatch('toast', message: 'Now at '.$this->visit->stage->label().'.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Call the visit off. Always available while it is open, always with a reason. */
    public function cancelVisit(VisitService $service): void
    {
        $visit = $this->visit;
        $this->authorize('manage', $visit);

        $this->validate(
            ['cancelNote' => ['required', 'string', 'min:3', 'max:255']],
            ['cancelNote.required' => 'Say why the visit is being called off.',
                'cancelNote.min' => 'Say why the visit is being called off.'],
        );

        try {
            $service->cancel($visit, Auth::id(), trim((string) $this->cancelNote));
        } catch (InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->cancelNote = null;
        unset($this->visit, $this->gate);

        $this->dispatch('toast', message: 'Visit cancelled.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);

        return view('livewire.visits.show', ['visit' => $visit])
            ->title($visit->visit_no);
    }
}
