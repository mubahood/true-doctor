<?php

namespace App\Livewire\Appointments\Concerns;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\AppointmentService;
use Livewire\Attributes\Computed;

/**
 * Everything that can be DONE to an appointment from a list.
 *
 * The diary and the check-in queue are two readings of the same day, and both
 * want the same vocabulary: move it along, end it with a reason, write up what
 * happened at it. Held once, because a second copy is how a queue ends up
 * still offering a bare "Completed" button after the diary has stopped —
 * exactly the contradiction this trait exists to make impossible.
 *
 * The host supplies nothing but a hook: `afterActingOnAppointment()`, for
 * whatever it caches about the rows it is drawing.
 */
trait ActsOnAppointments
{
    use \App\Livewire\Concerns\ChargesWorkDone;

    // ── Ending one: why ──────────────────────────────────────────────────

    public bool $showEnding = false;

    public ?int $endingId = null;

    public ?string $endingTo = null;

    public ?string $note = null;

    // ── Writing up what happened ─────────────────────────────────────────

    public bool $showOutcome = false;

    public ?int $outcomeId = null;

    /** The doctor's report — the same field an order carries. */
    public ?string $report = null;

    /**
     * Whether submitting also finishes the appointment.
     *
     * A consultation is not always written up in one sitting, and the choice
     * is between letting the doctor save halfway and watching them lose it.
     */
    public bool $completeIt = true;

    /** The appointment writes its lines through recordOutcome, not directly. */
    protected function chargeTo(): ?\App\Models\Order
    {
        return null;
    }

    protected function assertMayCharge(): void
    {
        //
    }

    /** Whatever the host has open that a dialog should not land on top of. */
    protected function beforeActingOnAppointment(): void
    {
        //
    }

    /** Whatever the host caches about the rows it draws. */
    protected function afterActingOnAppointment(): void
    {
        //
    }

    // ── Moving it along ──────────────────────────────────────────────────

    /**
     * Advance the appointment through the status machine.
     *
     * Confirming and checking in are the desk's whole job, and sending somebody
     * to a detail page to press one button is the page getting in the way. The
     * machine itself still lives in AppointmentService, which refuses an
     * illegal jump whatever asks for it.
     */
    public function advance(int $id, string $status, AppointmentService $service): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('update', $appointment);

        $to = AppointmentStatus::tryFrom($status);

        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown appointment status.', type: 'error');

            return;
        }

        $this->beforeActingOnAppointment();

        // Completing is not a status change: it is the moment somebody says what
        // was done. The machine still refuses an illegal jump; this refuses an
        // EMPTY one.
        if ($to === AppointmentStatus::Completed) {
            $this->openOutcome($appointment->id);

            return;
        }

        // Ending one is not the same as moving it along: it wants a reason, and
        // it cannot be undone.
        if (in_array($to, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            $this->endingId = $appointment->id;
            $this->endingTo = $to->value;
            $this->note = null;
            $this->showEnding = true;
            $this->resetErrorBag();

            return;
        }

        $this->applyTransition($appointment, $to, null, $service);
    }

    /** Confirm the cancel / no-show, with whatever reason was given. */
    public function endAppointment(AppointmentService $service): void
    {
        if (! $this->showEnding || $this->endingId === null || $this->endingTo === null) {
            return;
        }

        $appointment = Appointment::findOrFail($this->endingId);
        $this->authorize('update', $appointment);

        $to = AppointmentStatus::from($this->endingTo);
        $data = $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->applyTransition($appointment, $to, ($data['note'] ?? null) ?: null, $service);

        $this->reset(['showEnding', 'endingId', 'endingTo', 'note']);
    }

    public function cancelEnding(): void
    {
        $this->reset(['showEnding', 'endingId', 'endingTo', 'note']);
        $this->resetErrorBag();
    }

    protected function applyTransition(Appointment $appointment, AppointmentStatus $to, ?string $note, AppointmentService $service): void
    {
        try {
            $service->transition($appointment, $to, auth()->id(), $note);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->afterActingOnAppointment();
        $this->dispatch('toast', type: 'success', message: 'Moved to '.$to->label().'.');
    }

    /** The appointment whose ending is being explained, for the dialog to name. */
    #[Computed]
    public function ending(): ?Appointment
    {
        return $this->endingId === null ? null : Appointment::with('patient')->find($this->endingId);
    }

    // ── Writing up what happened ─────────────────────────────────────────

    /**
     * Open the outcome dialog.
     *
     * Completing an appointment is not a status change; it is the moment the
     * doctor says what was done. What these pages offered before was a button
     * saying "Completed" that recorded nothing at all.
     */
    public function openOutcome(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('update', $appointment);

        $this->beforeActingOnAppointment();

        if ($appointment->status->isTerminal()) {
            $this->dispatch('toast', type: 'error',
                message: 'This appointment is already '.strtolower($appointment->status->label()).'.');

            return;
        }

        if (! in_array($appointment->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)) {
            $this->dispatch('toast', type: 'error',
                message: 'Check the patient in first — there is nothing to report until they arrive.');

            return;
        }

        $this->outcomeId = $appointment->id;
        $this->completeIt = true;
        $this->formNonce++;

        // Pick up an unfinished write-up rather than showing a blank form over
        // work that is already on the order.
        $started = app(AppointmentService::class)->openConsultationFor($appointment);

        $this->report = $started?->report;
        $this->loadProvided($started);

        $this->resetErrorBag();
        $this->showOutcome = true;
    }

    public function closeOutcome(): void
    {
        $this->reset(['showOutcome', 'outcomeId', 'report', 'provided', 'completeIt']);
        $this->resetErrorBag();
    }

    /** The appointment whose outcome is being written, for the dialog to name. */
    #[Computed]
    public function outcome(): ?Appointment
    {
        return $this->outcomeId === null ? null : Appointment::with(['patient', 'doctor'])->find($this->outcomeId);
    }

    /**
     * Write the report and the lines, and close the appointment if asked.
     *
     * The service does the work — an order on the visit, its report, its
     * billable lines — because that is the visit module and this is not a
     * second copy of it.
     */
    public function saveOutcome(AppointmentService $service): void
    {
        if (! $this->showOutcome || $this->outcomeId === null) {
            return;
        }

        $appointment = Appointment::findOrFail($this->outcomeId);
        $this->authorize('update', $appointment);

        $data = $this->validate(
            array_merge(['report' => ['required', 'string', 'min:3', 'max:5000']], $this->providedRules()),
            $this->providedMessages(),
        );

        // Writing a clinical report is a clinical act. Whoever books and checks
        // somebody in is not, by that alone, the person who says what was found.
        $this->authorize('diagnose', $service->visitFor($appointment, auth()->id()));

        try {
            $order = $service->recordOutcome(
                $appointment,
                (string) $data['report'],
                array_map(
                    fn (array $line) => [
                        'kind' => $line['kind'],
                        'id' => (int) $line['id'],
                        'quantity' => (string) $line['quantity'],
                    ],
                    $this->provided,
                ),
                auth()->id(),
                $this->completeIt,
            );
        } catch (\RuntimeException $e) {
            $this->addError('report', $e->getMessage());

            return;
        }

        $completed = $this->completeIt;
        $charged = $order->items->count();
        $visitNo = $order->visit->visit_no;

        $this->closeOutcome();
        $this->afterActingOnAppointment();

        $this->dispatch('toast', type: 'success', message: ($completed ? 'Outcome recorded on ' : 'Saved on ').$visitNo
            .($charged > 0 ? ' — '.$charged.' '.\Illuminate\Support\Str::plural('line', $charged).' charged' : '')
            .($completed ? '.' : '. The patient is still with the doctor.'));
    }
}
