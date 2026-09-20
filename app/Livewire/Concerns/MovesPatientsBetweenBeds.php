<?php

namespace App\Livewire\Concerns;

use App\Enums\AdmissionStatus;
use App\Http\Requests\BedTransferRequest;
use App\Http\Requests\DischargeRequest;
use App\Models\Admission;
use App\Models\Bed;
use App\Services\AdmissionService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The two things that are ever done to a live admission: move the patient to
 * another bed, or close the stay.
 *
 * Both were written into the admission workspace, which is the one screen a
 * ward clerk is NOT looking at — they are looking at the occupancy board,
 * because that is where you can see which bed is free. Holding the pair here
 * means the board can offer them without the board and the workspace coming to
 * disagree about what a transfer costs or what a discharge bills.
 *
 * Every rule stays where it was: AdmissionService locks both beds, refuses a
 * closed admission and bills the stay; the validation is the same
 * BedTransferRequest / DischargeRequest the classic POSTs used.
 */
trait MovesPatientsBetweenBeds
{
    // ── Transfer ───────────────────────────────────────────────
    public bool $showTransfer = false;

    public ?int $to_bed_id = null;

    public ?string $transfer_reason = null;

    // ── Discharge ──────────────────────────────────────────────
    public bool $showDischarge = false;

    public string $outcome = 'discharged';

    public ?string $discharge_notes = null;

    /** Part of the bed picker's key, so the child remounts with the dialog. */
    public int $moveNonce = 0;

    /** Which admission these two dialogs act on. */
    abstract protected function movingAdmission(): ?Admission;

    /** Re-read whatever the screen is showing, now that it has changed. */
    abstract protected function afterMovingPatient(): void;

    public function openTransfer(): void
    {
        $this->authorize('manage', Admission::class);

        $this->reset(['to_bed_id', 'transfer_reason']);
        $this->moveNonce++;
        $this->resetErrorBag();
        $this->showTransfer = true;
    }

    public function transfer(AdmissionService $service): void
    {
        $this->authorize('manage', Admission::class);

        // Same rules the classic POST used — the exists() clause is tenant-scoped.
        // The keys are re-pointed rather than copied: the reason a patient is
        // MOVED and the reason they were ADMITTED are different questions, and
        // Admissions\Index asks both on one screen.
        $rules = BedTransferRequest::rulesFor();
        $data = $this->validate([
            'to_bed_id' => $rules['to_bed_id'],
            'transfer_reason' => $rules['reason'],
        ]);

        $admission = $this->movingAdmission();
        if ($admission === null) {
            $this->addError('to_bed_id', 'There is no live admission to transfer.');

            return;
        }

        $bed = Bed::findOrFail($data['to_bed_id']);

        try {
            // BedUnavailableException and the "active admission only" guard are
            // both RuntimeExceptions raised by AdmissionService.
            $service->transfer($admission, $bed, ($data['transfer_reason'] ?? null) ?: null, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('to_bed_id', $e->getMessage());

            return;
        }

        $this->showTransfer = false;
        $this->reset(['to_bed_id', 'transfer_reason']);
        $this->afterMovingPatient();
        $this->dispatch('toast', message: 'Patient transferred to '.$bed->name.'.', type: 'success');
        $this->dispatch('admission-updated');
    }

    public function openDischarge(): void
    {
        $this->authorize('manage', Admission::class);

        $this->reset(['discharge_notes']);
        $this->outcome = AdmissionStatus::Discharged->value;
        $this->resetErrorBag();
        $this->showDischarge = true;
    }

    public function discharge(AdmissionService $service): void
    {
        $this->authorize('manage', Admission::class);

        $data = $this->validate(DischargeRequest::rulesFor());

        $admission = $this->movingAdmission();
        if ($admission === null) {
            $this->addError('outcome', 'There is no live admission to close.');

            return;
        }

        try {
            $service->discharge(
                $admission,
                AdmissionStatus::from($data['outcome']),
                ($data['discharge_notes'] ?? null) ?: null,
                Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('outcome', $e->getMessage());

            return;
        }

        $this->showDischarge = false;
        $this->afterMovingPatient();
        $this->dispatch('toast', message: 'Admission closed.', type: 'success');
        $this->dispatch('admission-updated');
    }
}
