<?php

namespace App\Livewire\Visits\Panels;

use App\Services\DentalCaseSheetService;
use App\Support\DoctorSlots;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Two parts of the dental case sheet that read what was done today:
 * `done` lists the procedures finished and billed on this visit; `review`
 * suggests when to see the patient again (an extraction suggests a week) and
 * books it in one tap from the dentist's free times, with the visit as its
 * origin.
 *
 * @property-read array<string,mixed> $data
 * @property-read list<string> $slots
 */
class DentalFollowUp extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    #[Locked]
    public string $part = 'review';

    public ?string $date = null;

    public bool $booking = false;

    public function mount(int $visitId, string $part = 'review'): void
    {
        $this->visitId = $visitId;
        $this->part = $part === 'done' ? 'done' : 'review';
        $this->authorize('view', $this->visit);
        abort_unless(DentalCaseSheetService::canRead(auth()->user()), 403);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function data(): array
    {
        $sheets = app(DentalCaseSheetService::class);

        return $this->part === 'done'
            ? ['done' => $sheets->doneToday($this->visit)]
            : ['review' => $sheets->reviewSuggestion($this->visit), 'booked' => $sheets->reviewsBooked($this->visit)];
    }

    #[On('dental-changed')]
    #[On('visit-updated')]
    public function refresh(): void
    {
        unset($this->data);
    }

    public function doctorId(): int
    {
        return (int) ($this->visit->doctor_user_id ?? auth()->id());
    }

    public function startBooking(): void
    {
        abort_unless(auth()->user()->can('appointments.manage'), 403);
        $suggested = $this->data['review']['on'] ?? Carbon::today()->addWeek()->toDateString();
        // The suggested day, or the first day the dentist sits from then on.
        $this->date = $this->firstSittingDay($suggested);
        $this->resetErrorBag();
        $this->booking = true;
    }

    /** @return list<string> */
    #[Computed]
    public function slots(): array
    {
        if ($this->date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return [];
        }

        return DoctorSlots::open($this->doctorId(), $this->date, 30);
    }

    public function updatedDate(): void
    {
        unset($this->slots);
    }

    public function book(string $time, DentalCaseSheetService $sheets): void
    {
        abort_unless(auth()->user()->can('appointments.manage'), 403);
        $this->authorize('view', $this->visit);
        $why = $this->data['review']['why'] ?? 'Dental review';

        try {
            $appointment = $sheets->bookReview($this->visit, (string) $this->date, $time, auth()->user(), $this->doctorId(), $why);
        } catch (ValidationException $e) {
            $this->addError('review', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->booking = false;
        unset($this->data, $this->slots);
        $this->dispatch('toast', message: 'Review booked for '.$appointment->scheduled_at->format('D j M, H:i').'.', type: 'success');
    }

    private function firstSittingDay(string $from): string
    {
        $day = Carbon::parse($from);
        for ($i = 0; $i < 14; $i++) {
            if (DoctorSlots::open($this->doctorId(), $day->toDateString(), 30) !== []) {
                return $day->toDateString();
            }
            $day->addDay();
        }

        return $from;
    }

    public function render()
    {
        return view('livewire.visits.panels.dental-follow-up', [
            'note' => $this->booking && $this->slots === [] ? DoctorSlots::note($this->doctorId(), $this->date, 30) : null,
        ]);
    }
}
