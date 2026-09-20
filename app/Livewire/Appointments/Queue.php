<?php

namespace App\Livewire\Appointments;

use App\Enums\AppointmentStatus;
use App\Livewire\Appointments\Concerns\ActsOnAppointments;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Today's check-in queue — a reception board, not a table of today's rows.
 *
 * A queue answers two questions a list cannot: WHO IS WAITING, and HOW LONG
 * HAVE THEY BEEN. The old board showed a scheduled time and a status badge, so
 * somebody who arrived at 08:55 and had been sitting forty minutes looked
 * exactly like somebody who had just walked in. It also drew every legal
 * transition as its own button — four to a row, including a "Completed" that
 * could no longer do anything, because completing an appointment means
 * recording what was done at it (docs/scheduling.md).
 *
 * So: three lanes in the order the desk thinks in — with the doctor now,
 * waiting, still expected — each row carrying its own clock, one action, and
 * the same dialogs the diary uses (ActsOnAppointments). `wire:poll` keeps a
 * screen left open on the desk current.
 *
 * @property-read Collection<int,Appointment> $rows
 * @property-read array<string,array{title:string,note:string,rows:Collection<int,Appointment>}> $lanes
 * @property-read array{expected:int,waiting:int,seeing:int,longest:int|null,seen:int} $tally
 */
#[Layout('layouts.admin')]
class Queue extends Component
{
    use ActsOnAppointments, AuthorizesRequests;

    /** Statuses that still belong on the queue. */
    private const ACTIVE = [
        AppointmentStatus::Scheduled,
        AppointmentStatus::Confirmed,
        AppointmentStatus::CheckedIn,
        AppointmentStatus::InProgress,
    ];

    /** Waiting longer than this is the thing the board exists to show. */
    public const WAIT_WARN = 20;

    public const WAIT_BAD = 45;

    /** One clinic at a time, when the desk is running several. */
    #[Url(history: true, except: '')]
    public string $doctor = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Appointment::class);
    }

    /** The board is what changed; nothing else on this page caches rows. */
    protected function afterActingOnAppointment(): void
    {
        unset($this->rows);
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($this->providedPicked($name, $id)) {
            return;
        }

        if ($name === 'doctor') {
            $this->doctor = (string) $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'doctor') {
            $this->doctor = '';
        }
    }

    /** @return Collection<int,Appointment> */
    #[Computed]
    public function rows(): Collection
    {
        return Appointment::with(['patient', 'doctor', 'room'])
            ->forDate(now()->format('Y-m-d'))
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, self::ACTIVE))
            ->when($this->doctor !== '', fn (Builder $q) => $q->where('doctor_user_id', (int) $this->doctor))
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * The board, in the order a desk thinks about it.
     *
     * With the doctor first because that is what is happening now; waiting
     * next, LONGEST FIRST, because that is the queue and the person who has
     * been there an hour is the one being failed; expected last, in clock
     * order, because it has not happened yet.
     *
     * @return array<string,array{title:string,note:string,rows:Collection<int,Appointment>}>
     */
    #[Computed]
    public function lanes(): array
    {
        $rows = $this->rows;

        $seeing = $rows->where('status', AppointmentStatus::InProgress);
        $waiting = $rows->where('status', AppointmentStatus::CheckedIn)
            ->sortBy(fn (Appointment $a) => ($a->checked_in_at ?? $a->scheduled_at)->timestamp);
        $expected = $rows->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed]);

        return [
            'seeing' => [
                'title' => 'With the doctor',
                'note' => 'Being seen now.',
                'rows' => $seeing->values(),
            ],
            'waiting' => [
                'title' => 'Waiting',
                'note' => 'Arrived and not yet called. Longest wait first.',
                'rows' => $waiting->values(),
            ],
            'expected' => [
                'title' => 'Expected',
                'note' => 'Booked for today and not arrived.',
                'rows' => $expected->values(),
            ],
        ];
    }

    /**
     * How long this patient has been sitting, in minutes.
     *
     * From the moment they were checked in — not from their appointment time,
     * which is what they were promised rather than what happened.
     */
    public function waitedMinutes(Appointment $appointment): ?int
    {
        return $appointment->checked_in_at === null
            ? null
            : max(0, $appointment->checked_in_at->diffInMinutes(now()));
    }

    /**
     * How late an expected patient is, in minutes — negative if still to come.
     */
    public function lateMinutes(Appointment $appointment): int
    {
        return (int) $appointment->scheduled_at->diffInMinutes(now(), false);
    }

    /** '', 'warn' or 'bad' — how a wait should read at a glance. */
    public function waitTone(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '',
            $minutes >= self::WAIT_BAD => 'bad',
            $minutes >= self::WAIT_WARN => 'warn',
            default => '',
        };
    }

    /** "1h 05m" reads faster than "65 minutes" on a board glanced at. */
    public function clock(int $minutes): string
    {
        return $minutes < 60
            ? $minutes.'m'
            : intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * What the desk needs to know without counting rows.
     *
     * @return array{expected:int,waiting:int,seeing:int,longest:int|null,seen:int}
     */
    #[Computed]
    public function tally(): array
    {
        $lanes = $this->lanes;
        $waits = $lanes['waiting']['rows']->map(fn (Appointment $a) => $this->waitedMinutes($a) ?? 0);

        return [
            'expected' => $lanes['expected']['rows']->count(),
            'waiting' => $lanes['waiting']['rows']->count(),
            'seeing' => $lanes['seeing']['rows']->count(),
            'longest' => $waits->isEmpty() ? null : (int) $waits->max(),
            'seen' => Appointment::forDate(now()->format('Y-m-d'))
                ->where('status', AppointmentStatus::Completed->value)
                ->when($this->doctor !== '', fn (Builder $q) => $q->where('doctor_user_id', (int) $this->doctor))
                ->count(),
        ];
    }

    /** The doctor the board is pinned to, so the picker can show it. */
    #[Computed]
    public function filterDoctor(): ?User
    {
        return $this->doctor === '' ? null : User::currentHospital()->find((int) $this->doctor);
    }

    public function clearFilters(): void
    {
        $this->doctor = '';
        unset($this->rows);
    }

    public function render()
    {
        $this->authorize('viewAny', Appointment::class);

        return view('livewire.appointments.queue')->title('Check-in queue');
    }
}
