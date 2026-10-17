<?php

namespace App\Livewire\Appointments;

use App\Livewire\Concerns\WithTable;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\FollowUpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Recalls and reviews due (docs/follow-up.md): the receptionist's calling
 * list. Reviews booked for this week, reviews missed, dental check-ups due
 * or overdue with nothing booked. One tap to call; a text through
 * FollowUpService, which leaves consent and numbers to PatientMessenger.
 */
#[Layout('layouts.admin')]
class Recalls extends Component
{
    use WithTable;

    #[Url(history: true, except: 'week')]
    public string $show = 'week';

    /** @return list<string> */
    protected function resetsPage(): array
    {
        return ['show'];
    }

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('appointments.view'), 403);
    }

    private function service(): FollowUpService
    {
        return app(FollowUpService::class);
    }

    /** Text one patient that their review or check-up is due. */
    public function text(string $kind, int $id): void
    {
        abort_unless(Auth::user()?->can('appointments.manage'), 403);

        // Through the tenant scope: another clinic's id is simply not found.
        if ($kind === 'recall') {
            $patient = Patient::query()->findOrFail($id);
            if (! $this->service()->recallDue($patient)) {
                $this->dispatch('toast', message: 'No dental check-up is due for this patient.', type: 'error');

                return;
            }
            $key = 'recall:'.$patient->next_recall_on?->toDateString();
        } else {
            $appointment = Appointment::query()->with('patient')->whereNotNull('origin_visit_id')->findOrFail($id);
            $patient = $appointment->patient ?? abort(404);
            $key = 'review:'.$appointment->uuid;
        }

        $result = $this->service()->remind($patient, $key);
        $this->dispatch('toast', message: $result['message'], type: $result['sent'] ? 'success' : 'error');
    }

    public function render()
    {
        abort_unless(Auth::user()?->can('appointments.view'), 403);

        $show = isset(FollowUpService::LISTS[$this->show]) ? $this->show : 'week';
        $service = $this->service();

        /** @var Builder<Appointment>|Builder<Patient> $query */
        $query = match ($show) {
            'missed' => $service->missedReviews(),
            'recalls' => $service->recallsDue(),
            default => $service->reviewsThisWeek(),
        };

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $byName = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)->orWhere('patient_no', 'like', $term)->orWhere('phone_1', 'like', $term));
            $show === 'recalls' ? $byName($query) : $query->whereHas('patient', $byName);
        }

        return view('livewire.appointments.recalls', [
            'rows' => $query->paginate($this->perPage),
            'shown' => $show,
            'counts' => $service->counts(),
            'canText' => Auth::user()->can('appointments.manage'),
        ])->title('Recalls and reviews due');
    }
}
