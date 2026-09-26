<?php

namespace App\Livewire\Appointments;

use App\Livewire\Appointments\Concerns\ActsOnAppointments;
use App\Models\Appointment;
use App\Models\User;
use App\Support\CheckInQueue;
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

    /** Thresholds live on the shared queue, so the web and the app agree. */
    public const WAIT_WARN = CheckInQueue::WAIT_WARN;

    public const WAIT_BAD = CheckInQueue::WAIT_BAD;

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

    private function queue(): CheckInQueue
    {
        return app(CheckInQueue::class);
    }

    private function doctorId(): ?int
    {
        return $this->doctor === '' ? null : (int) $this->doctor;
    }

    /**
     * Today's rows, lanes, clocks and tally come from App\Support\CheckInQueue —
     * the same object GET /api/v1/queue answers from.
     *
     * @return Collection<int,Appointment>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->queue()->rows($this->doctorId());
    }

    /** @return array<string,array{title:string,note:string,rows:Collection<int,Appointment>}> */
    #[Computed]
    public function lanes(): array
    {
        return $this->queue()->lanes($this->rows);
    }

    public function waitedMinutes(Appointment $appointment): ?int
    {
        return $this->queue()->waitedMinutes($appointment);
    }

    public function lateMinutes(Appointment $appointment): int
    {
        return $this->queue()->lateMinutes($appointment);
    }

    public function waitTone(?int $minutes): string
    {
        return $this->queue()->waitTone($minutes);
    }

    public function clock(int $minutes): string
    {
        return $this->queue()->clock($minutes);
    }

    /** @return array{expected:int,waiting:int,seeing:int,longest:int|null,seen:int} */
    #[Computed]
    public function tally(): array
    {
        return $this->queue()->tally($this->lanes, $this->doctorId());
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
