<?php

namespace App\Livewire\Visits\Panels;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Services\FollowUpService;
use App\Support\DoctorSlots;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Book a review from the visit (docs/follow-up.md): the date suggested by what
 * was done today, the doctor's free times on that day, one press to book.
 * The booking is an ordinary appointment that remembers this visit, so it
 * shows in the diary, gets the day-before reminder, and lands on Recalls and
 * reviews due if the patient does not come.
 *
 * @property-read array{days:int, reason:string, date:string} $suggestion
 * @property-read Collection<int, Appointment> $booked
 * @property-read list<string> $slots
 */
class Review extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    public string $date = '';

    public string $time = '';

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
        abort_unless(Auth::user()?->can('appointments.manage'), 403);

        $this->date = $this->suggestion['date'];
    }

    private function service(): FollowUpService
    {
        return app(FollowUpService::class);
    }

    /** @return array{days:int, reason:string, date:string} */
    #[Computed]
    public function suggestion(): array
    {
        return $this->service()->suggestion($this->visit);
    }

    /** @return Collection<int, Appointment> */
    #[Computed]
    public function booked(): Collection
    {
        return $this->service()->reviewsFor($this->visit);
    }

    /** @return list<string> */
    #[Computed]
    public function slots(): array
    {
        $doctor = $this->visit->doctor_user_id;
        if ($doctor === null || ! $this->validDate()) {
            return [];
        }

        return DoctorSlots::open($doctor, $this->date, FollowUpService::REVIEW_MINUTES);
    }

    public function slotsNote(): ?string
    {
        if ($this->visit->doctor_user_id === null) {
            return 'This visit has no doctor yet. Book the review from Appointments instead.';
        }

        return $this->validDate() ? DoctorSlots::note($this->visit->doctor_user_id, $this->date, FollowUpService::REVIEW_MINUTES) : 'Choose a day.';
    }

    private function validDate(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 && strtotime($this->date) !== false;
    }

    public function updatedDate(): void
    {
        $this->time = '';
        unset($this->slots);
    }

    public function book(): void
    {
        $this->authorize('view', $this->visit);
        abort_unless(Auth::user()?->can('appointments.manage'), 403);

        $this->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ], [
            'time.required' => 'Choose a time.',
            'date.after_or_equal' => 'Choose today or a later day.',
        ]);

        try {
            $appointment = $this->service()->bookReview($this->visit, $this->date, $this->time, null, Auth::id());
        } catch (SlotUnavailableException|\RuntimeException $e) {
            $this->addError('time', $e->getMessage());

            return;
        }

        $this->time = '';
        unset($this->booked, $this->slots);
        $this->dispatch('toast', message: 'Review booked for '.Carbon::parse($appointment->scheduled_at)->format('D j M, H:i').'.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.review');
    }
}
