<?php

namespace App\Livewire\Appointments;

use App\Enums\AppointmentStatus;
use App\Http\Requests\AppointmentRequest;
use App\Models\Appointment;
use App\Models\Room;
use App\Services\AppointmentService;
use App\Services\VisitService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Appointment detail (Detail shape, plan §4.4) — replaces
 * admin/appointments/show.blade.php and AppointmentController@show/@transition.
 *
 * Three kinds of write live here, all Livewire actions:
 *   • transition()    — the AppointmentStatus machine, through AppointmentService.
 *                       Cancel / no-show carry a reason and ask wire:confirm first.
 *   • reschedule()    — the slide-over that restores the feature lost when the
 *                       classic edit page was deleted; AppointmentService
 *                       re-validates the slot (availability, alignment, overlap).
 *   • openVisit() — a checked-in patient becomes a visit linked back
 *                       to this appointment; the only action that leaves the page.
 *
 * Only RuntimeException (SlotUnavailableException, InvalidStatusTransitionException
 * and the services' own guards all extend it) is caught — house rule 5.
 *
 * @property-read Appointment $appointment
 * @property-read Collection<int,Room> $rooms
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $appointmentId;

    /** Reason attached to a cancel / no-show transition. */
    public ?string $note = null;

    // ── Reschedule slide-over ──────────────────────────────────
    /** Part of every picker's key, so a child remounts with the dialog. */
    public int $formNonce = 0;

    public bool $showReschedule = false;

    public ?string $scheduled_at = null;

    public int $duration_minutes = 30;

    public ?int $room_id = null;

    public function mount(string $appointment): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $model = Appointment::where('uuid', $appointment)->firstOrFail();
        $this->authorize('view', $model);

        $this->appointmentId = $model->id;
    }

    #[Computed]
    public function appointment(): Appointment
    {
        return Appointment::with(['patient', 'doctor', 'department', 'room', 'history.changedBy'])
            ->findOrFail($this->appointmentId);
    }

    // `rooms` was an uncapped `Room::…->get()` on every render, drawn into a
    // <select>. It is a picker now (docs/visits.md).

    /** Advance (or cancel) the appointment through the AppointmentStatus machine. */
    public function transition(string $status, AppointmentService $service): void
    {
        $appointment = $this->appointment;
        $this->authorize('update', $appointment);

        $to = AppointmentStatus::tryFrom($status);
        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown appointment status.', type: 'error');

            return;
        }

        $data = $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $service->transition($appointment, $to, Auth::id(), ($data['note'] ?? null) ?: null);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->note = null;
        unset($this->appointment);
        $this->dispatch('toast', message: "Appointment moved to {$to->label()}.", type: 'success');
    }

    // ── Reschedule ─────────────────────────────────────────────

    public function openReschedule(): void
    {
        $appointment = $this->appointment;
        $this->authorize('update', $appointment);

        $this->resetErrorBag();
        $this->scheduled_at = $appointment->scheduled_at->format('Y-m-d\TH:i');
        $this->duration_minutes = $appointment->duration_minutes;
        $this->room_id = $appointment->room_id;
        $this->formNonce++;
        $this->showReschedule = true;
    }

    /** The room picker reports its pick. Only that one field, by name. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name === 'room_id') {
            $this->room_id = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'room_id') {
            $this->room_id = null;
        }
    }

    public function reschedule(AppointmentService $service): void
    {
        $appointment = $this->appointment;
        $this->authorize('update', $appointment);

        // One validation source with the booking modal (§4.5, C10).
        $booking = AppointmentRequest::rulesFor();
        $data = $this->validate([
            'scheduled_at' => $booking['scheduled_at'],
            'duration_minutes' => $booking['duration_minutes'],
            'room_id' => $booking['room_id'],
        ]);

        try {
            $service->reschedule(
                $appointment,
                (string) $data['scheduled_at'],
                (int) $data['duration_minutes'],
                isset($data['room_id']) ? (int) $data['room_id'] : null,
                Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('scheduled_at', $e->getMessage());

            return;
        }

        $this->showReschedule = false;
        unset($this->appointment);
        $this->dispatch('toast', message: 'Appointment rescheduled.', type: 'success');
    }

    /** Turn a checked-in appointment into a visit and go to it. */
    public function openVisit(VisitService $service): mixed
    {
        $appointment = $this->appointment;
        $this->authorize('update', $appointment);

        if ($appointment->status !== AppointmentStatus::CheckedIn) {
            $this->dispatch('toast', message: 'Only a checked-in appointment can start a visit.', type: 'error');

            return null;
        }

        try {
            $visit = $service->open([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'doctor_user_id' => $appointment->doctor_user_id,
                'department_id' => $appointment->department_id,
                'reason' => $appointment->reason,
            ], Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        session()->flash('success', "Visit {$visit->visit_no} opened.");

        return $this->redirect(route('admin.visits.show', $visit), navigate: true);
    }

    public function render()
    {
        $appointment = $this->appointment;
        $this->authorize('view', $appointment);

        return view('livewire.appointments.show', ['appointment' => $appointment])
            ->title($appointment->patient->full_name);
    }
}
