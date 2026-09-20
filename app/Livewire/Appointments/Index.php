<?php

namespace App\Livewire\Appointments;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\AppointmentRequest;
use App\Livewire\Appointments\Concerns\ActsOnAppointments;
use App\Livewire\Concerns\WithTable;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AppointmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Live appointments diary — date + doctor + status filters + an AJAX slide-over
 * to book. Booking runs through AppointmentService (conflict detection,
 * slotting); this component authorizes, validates (AppointmentRequest::rulesFor)
 * and reports. The patient picker is <livewire:ui.select-search> so the whole
 * patient table is never queried or rendered (C4/D3/L1).
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use ActsOnAppointments, AuthorizesRequests, WithTable;

    /** Modal fields a <livewire:ui.select-search> child may set. */
    private const PICKERS = ['origin_visit_id', 'patient_id', 'doctor_user_id', 'department_id', 'room_id'];

    /** How the diary is read: as a list, or as the week it belongs to. */
    public const VIEWS = ['list', 'calendar'];

    #[Url(history: true, except: 'list')]
    public string $view = 'list';

    #[Url(history: true)]
    public string $date = '';

    #[Url(history: true)]
    public string $doctor = '';

    #[Url(history: true)]
    public string $status = '';

    /** Filters a picker feeds, which are URL-bound rather than modal state. */
    private const FILTER_PICKERS = ['doctor'];

    // ── Booking modal state ────────────────────────────────────
    public bool $showForm = false;

    /**
     * The appointment being changed, or null when booking a new one.
     *
     * One dialog answers both: the questions are identical, and a second copy
     * of the day-and-free-time picker would be a second place for it to drift.
     */
    public ?int $editingId = null;

    /**
     * The appointment being read without leaving the diary.
     *
     * Everything the detail page says, in a dialog over the list: opening a
     * whole page to answer "what is this one, again?" loses the place in the
     * list somebody is working down, and the answer is six lines long.
     */
    public bool $showPeek = false;

    public ?int $peekId = null;

    public ?int $patient_id = null;

    /**
     * The visit this booking was arranged during, where it was.
     *
     * An appointment used to be raised against a patient picked out of the
     * whole register, which is right for somebody ringing up and wrong for the
     * commonest case by far: "come back in two weeks", said in the consulting
     * room. Choosing the visit brings its patient, its doctor and its
     * department with it, and the follow-up keeps a way back to the attendance
     * that prompted it (docs/visits.md).
     */
    public ?int $origin_visit_id = null;

    public ?int $doctor_user_id = null;

    public ?int $department_id = null;

    public ?int $room_id = null;

    public ?string $scheduled_at = null;

    public int $duration_minutes = 30;

    public ?string $source = null;

    public ?string $reason = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Appointment::class);

        // The date starts EMPTY. Defaulting it to today made the page a diary
        // for one day and hid everything else behind a filter nobody had set —
        // a booking made for next week disappeared the moment it was saved.
        // Empty means "every appointment", soonest first, which is the list
        // somebody opening this page is actually looking for.
    }

    protected function resetsPage(): array
    {
        return ['date', 'doctor', 'status', 'view'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['scheduled_at', 'status'];
    }

    /**
     * `booted` guards a hand-typed URL, `updatedView` guards the same value
     * arriving from the page — the hook runs on hydration, which is BEFORE the
     * update is applied, so one without the other leaves a gap.
     */
    public function booted(): void
    {
        $this->settleView();
    }

    public function updatedView(): void
    {
        $this->settleView();
    }

    private function settleView(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'list';
        }
    }

    public function create(): void
    {
        $this->authorize('create', Appointment::class);
        $this->reset(['editingId', 'origin_visit_id', 'patient_id', 'doctor_user_id', 'department_id', 'room_id', 'scheduled_at', 'source', 'reason']);
        $this->duration_minutes = 30;
        $this->formNonce++;
        $this->forgetWhenChoices();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    /**
     * Change an appointment from the row it is on.
     *
     * Moving one was a page away, behind a detail screen — and moving one is
     * the commonest thing anybody does to an appointment after making it. The
     * dialog is the booking dialog, so a reschedule gets the same day strip and
     * the same list of times the doctor is actually free.
     */
    public function edit(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('update', $appointment);

        // Two dialogs stacked over each other is a place to get lost in; the
        // quick view steps aside for the one it asked for.
        $this->closePeek();

        if ($appointment->status->isTerminal()) {
            $this->dispatch('toast', type: 'error',
                message: 'A '.strtolower($appointment->status->label()).' appointment cannot be moved. Book a new one.');

            return;
        }

        $this->editingId = $appointment->id;
        $this->origin_visit_id = $appointment->origin_visit_id;
        $this->patient_id = $appointment->patient_id;
        $this->doctor_user_id = $appointment->doctor_user_id;
        $this->department_id = $appointment->department_id;
        $this->room_id = $appointment->room_id;
        $this->scheduled_at = $appointment->scheduled_at->format('Y-m-d\TH:i');
        $this->duration_minutes = $appointment->duration_minutes;
        $this->source = $appointment->source->value;
        $this->reason = $appointment->reason;

        $this->formNonce++;
        $this->forgetWhenChoices();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    // ── Reading one without leaving the list ─────────────────────────────

    public function peek(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('view', $appointment);

        $this->peekId = $appointment->id;
        $this->showPeek = true;
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    /** Everything the dialog shows, in one read. */
    #[Computed]
    public function peeked(): ?Appointment
    {
        return $this->peekId === null ? null : Appointment::with([
            'patient', 'doctor', 'department', 'room', 'originVisit', 'visit', 'history.changedBy',
        ])->find($this->peekId);
    }

    /** Close the quick view before a dialog lands on top of it. */
    protected function beforeActingOnAppointment(): void
    {
        $this->closePeek();
    }

    /** The row that changed is a row this page is drawing. */
    protected function afterActingOnAppointment(): void
    {
        unset($this->peeked);
    }

    /** A <livewire:ui.select-search> child reports a pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, self::FILTER_PICKERS, true)) {
            $this->{$name} = (string) $id;
            $this->resetPage();

            return;
        }

        if ($this->providedPicked($name, $id)) {
            return;
        }

        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = $id;

        if ($name === 'doctor_user_id') {
            $this->forgetWhenChoices();
        }

        // Choosing a department narrows the doctor box to it, so a doctor
        // already picked from the whole hospital has to be re-offered —
        // exactly as the visit form does it (docs/visits.md).
        if ($name === 'department_id') {
            $this->doctor_user_id = null;
            $this->formNonce++;
        }

        // Choosing a visit answers three of the questions below it: who this is
        // for, who was seeing them and in which department. Filling them in is
        // the whole point of picking the visit rather than the patient.
        if ($name === 'origin_visit_id') {
            $this->carryOverFromVisit();
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, self::FILTER_PICKERS, true)) {
            $this->{$name} = '';
            $this->resetPage();

            return;
        }

        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = null;

        if ($name === 'doctor_user_id') {
            $this->forgetWhenChoices();
        }

        // Clearing the department widens the doctor box back out; it must not
        // silently take the doctor with it.
        if ($name === 'department_id') {
            $this->formNonce++;
        }

        // Dropping the visit releases the patient it was holding, but keeps
        // what has been typed since — clearing a picker must not wipe a form.
        if ($name === 'origin_visit_id') {
            $this->patient_id = null;
            $this->formNonce++;
        }
    }

    /**
     * Carry the visit's own answers into the booking.
     *
     * The patient is not merely suggested: an appointment whose origin says one
     * person and whose patient says another is a record nobody can act on, so
     * the field is filled from the visit and shown rather than offered.
     */
    private function carryOverFromVisit(): void
    {
        $visit = Visit::find($this->origin_visit_id);

        if ($visit === null) {
            $this->origin_visit_id = null;

            return;
        }

        $this->patient_id = $visit->patient_id;
        $this->department_id ??= $visit->department_id;
        $this->doctor_user_id ??= $visit->doctor_user_id;
        $this->formNonce++;
    }

    /** The visit a follow-up is being booked out of, for the dialog to name. */
    #[Computed]
    public function originVisit(): ?Visit
    {
        return $this->origin_visit_id === null ? null : Visit::with('patient')->find($this->origin_visit_id);
    }

    /** Shared with AppointmentRequest — one source of truth (§4.5, C10). */
    protected function rules(): array
    {
        return AppointmentRequest::rulesFor();
    }

    public function save(AppointmentService $service): void
    {
        if ($this->editingId !== null) {
            $this->saveChanges($service);

            return;
        }

        $this->authorize('create', Appointment::class);

        $data = $this->validate();
        if (($data['reason'] ?? '') === '') {
            $data['reason'] = null;
        }

        try {
            $appointment = $service->book($data, auth()->id());
        } catch (SlotUnavailableException|\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'The appointment could not be booked. Please try again.', type: 'error');

            return;
        }

        // The dialog closes and the list is where it was. Redirecting to the
        // new record threw whoever opened it out of the list they were working
        // down, and the commonest thing after creating one is creating the
        // next — it is in the table behind the dialog, and the toast names it.
        $this->showForm = false;
        $this->resetPage();
        $this->dispatch('toast', message: 'Appointment booked for '
            .$appointment->scheduled_at->format('d M Y · H:i').'.', type: 'success');
    }

    /**
     * Save a change to an existing appointment.
     *
     * The time, the length and the room go through AppointmentService, which
     * re-checks availability, alignment and overlap and writes the trail. The
     * rest — the room aside, what the appointment is FOR — is plain record
     * keeping and is saved beside it.
     */
    private function saveChanges(AppointmentService $service): void
    {
        $appointment = Appointment::findOrFail($this->editingId);
        $this->authorize('update', $appointment);

        $data = $this->validate();

        try {
            $service->reschedule(
                $appointment,
                (string) $data['scheduled_at'],
                (int) $data['duration_minutes'],
                isset($data['room_id']) ? (int) $data['room_id'] : null,
                auth()->id(),
            );
        } catch (\RuntimeException $e) {
            $this->addError('scheduled_at', $e->getMessage());

            return;
        }

        $appointment->forceFill([
            'reason' => ($data['reason'] ?? '') === '' ? null : $data['reason'],
            'source' => $data['source'],
            'department_id' => $data['department_id'] ?? null,
        ])->save();

        $this->showForm = false;
        $this->editingId = null;
        $this->dispatch('toast', type: 'success', message: 'Appointment moved to '
            .$appointment->fresh()->scheduled_at->format('d M Y · H:i').'.');
    }

    // ── The week, when the list is not the shape of the question ─────────

    /** Monday of the week the diary is looking at. */
    public function weekStart(): \Illuminate\Support\Carbon
    {
        $anchor = $this->date === '' || ! $this->isRealDate($this->date)
            ? now()
            : \Illuminate\Support\Carbon::parse($this->date);

        return $anchor->copy()->startOfWeek();
    }

    /** Move the calendar a week at a time, keeping the day of the week. */
    public function shiftWeek(int $weeks): void
    {
        $this->date = $this->weekStart()->addWeeks($weeks)->format('Y-m-d');
        $this->resetPage();
    }

    public function thisWeek(): void
    {
        $this->date = now()->startOfWeek()->format('Y-m-d');
        $this->resetPage();
    }

    /**
     * The seven days of the shown week, each carrying its appointments.
     *
     * One query for the week rather than one per day, capped: a week that
     * somehow held thousands should draw a truncated calendar rather than
     * exhaust memory drawing all of them.
     *
     * @return list<array{date:\Illuminate\Support\Carbon, appointments:\Illuminate\Support\Collection<int,Appointment>}>
     */
    #[Computed]
    public function week(): array
    {
        $start = $this->weekStart();
        $end = $start->copy()->addDays(7);

        $appointments = $this->filtered()
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get()
            ->groupBy(fn (Appointment $a) => $a->scheduled_at->format('Y-m-d'));

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);

            $days[] = [
                'date' => $day,
                'appointments' => $appointments->get($day->format('Y-m-d'), collect()),
            ];
        }

        return $days;
    }

    // Doctors, departments and rooms were three uncapped `->get()`s running on
    // EVERY render of this page — the whole users table to draw one <select>.
    // They are <livewire:ui.select-search> now, which searches, caps its own
    // results and offers a browse list on focus (docs/visits.md).

    /**
     * Does the chosen department actually have doctors in it?
     *
     * A department narrows the doctor box through `staff_profiles`. A hospital
     * that has not filled those in has no doctor in any department, so the
     * picker offers the whole hospital instead — and the hint beside it has to
     * say that rather than claiming a narrowing that is not happening.
     */
    #[Computed]
    public function departmentHasDoctors(): bool
    {
        if ($this->department_id === null) {
            return false;
        }

        return User::currentHospital()
            ->where('role', 'doctor')
            ->whereHas('staffProfile', fn ($q) => $q->where('department_id', $this->department_id))
            ->exists();
    }

    // ── When: the day, then a time the doctor is actually free ───────────

    /**
     * The date half of `scheduled_at`, or null while nothing is chosen.
     *
     * `scheduled_at` is one datetime-local string, but it answers two separate
     * questions and the answers narrow each other: which day, and then which of
     * that day's free times.
     */
    public function chosenDate(): ?string
    {
        return $this->scheduled_at === null || $this->scheduled_at === ''
            ? null
            : substr($this->scheduled_at, 0, 10);
    }

    public function chosenTime(): ?string
    {
        return $this->scheduled_at === null || strlen($this->scheduled_at) < 16
            ? null
            : substr($this->scheduled_at, 11, 5);
    }

    /** Pick the day, keeping a time already chosen if it survives the move. */
    public function chooseDay(string $date): void
    {
        if (! $this->isRealDate($date)) {
            return;
        }

        $time = $this->chosenTime();
        $this->scheduled_at = $date.'T'.($time ?? '00:00');

        // A time that was free on Monday need not be free on Tuesday. Rather
        // than carry a stale answer into a booking that will be refused on
        // save, the day lands on the doctor's first free time — which is the
        // answer in most cases, and one click away from the rest.
        $open = $this->openSlots();

        if ($time === null || ! in_array($time, $open, true)) {
            $this->scheduled_at = $open === [] ? $date.'T09:00' : $date.'T'.$open[0];
        }
    }

    public function chooseTime(string $time): void
    {
        $date = $this->chosenDate();

        if ($date === null || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return;
        }

        $this->scheduled_at = $date.'T'.$time;
    }

    /** A different doctor or a different length is a different set of times. */
    public function updatedDurationMinutes(): void
    {
        $this->forgetWhenChoices();
    }

    private function forgetWhenChoices(): void
    {
        unset($this->openSlots, $this->dayChoices);
    }

    private function isRealDate(string $date): bool
    {
        return (bool) \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    }

    /**
     * The next fortnight as one click each, saying which days the doctor sits.
     *
     * "18/09/2026, 20:46" typed into a datetime box is three chances to get it
     * wrong and no indication that the doctor does not work that day. A named
     * day that knows whether anybody is there is one click and no guessing.
     *
     * @return list<array{date:string, label:string, sub:string, sits:bool}>
     */
    #[Computed]
    public function dayChoices(): array
    {
        $sitsOn = $this->doctor_user_id === null ? null : \App\Models\DoctorSchedule::query()
            ->where('user_id', $this->doctor_user_id)
            ->where('is_active', true)
            ->pluck('weekday')
            ->map(fn ($d) => $d instanceof \App\Enums\Weekday ? $d->value : (int) $d)
            ->all();

        $days = [];

        for ($i = 0; $i < 14; $i++) {
            $day = now()->startOfDay()->addDays($i);

            $days[] = [
                'date' => $day->format('Y-m-d'),
                'label' => match ($i) {
                    0 => 'Today',
                    1 => 'Tomorrow',
                    default => $day->format('D'),
                },
                'sub' => $i < 2 ? $day->format('D j M') : $day->format('j M'),
                'sits' => $sitsOn === null || in_array($day->dayOfWeek, $sitsOn, true),
            ];
        }

        return $days;
    }

    /**
     * The times this doctor is free on the chosen day, at the chosen length.
     *
     * Generated exactly the way AppointmentService::assertBookable checks them
     * — the window's own slot grid, the whole appointment inside one window,
     * nothing overlapping a booking that still occupies its slot — so a time
     * offered here is a time that books. Anything else would be an invitation
     * to an error message.
     *
     * @return list<string> "HH:MM", soonest first
     */
    #[Computed]
    public function openSlots(): array
    {
        $date = $this->chosenDate();

        if ($this->doctor_user_id === null || $date === null || ! $this->isRealDate($date)) {
            return [];
        }

        $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        $length = max(5, $this->duration_minutes);

        $windows = \App\Models\DoctorSchedule::query()
            ->where('user_id', $this->doctor_user_id)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        if ($windows->isEmpty()) {
            return [];
        }

        $taken = Appointment::query()
            ->where('doctor_user_id', $this->doctor_user_id)
            ->whereDate('scheduled_at', $day->toDateString())
            // An appointment being moved must not block its own time: leaving
            // it where it is has to stay one of the answers, and the service
            // ignores it too (assertBookable's $ignoreId).
            ->when($this->editingId !== null, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->whereIn('status', array_map(
                fn (AppointmentStatus $s) => $s->value,
                array_filter(AppointmentStatus::cases(), fn (AppointmentStatus $s) => $s->occupiesSlot()),
            ))
            ->get(['scheduled_at', 'ends_at']);

        $slots = [];

        foreach ($windows as $window) {
            $cursor = $day->copy()->setTimeFromTimeString($window->start_time);
            $closes = $day->copy()->setTimeFromTimeString($window->end_time);

            while ($cursor->copy()->addMinutes($length) <= $closes) {
                $ends = $cursor->copy()->addMinutes($length);

                $free = ! $cursor->isPast()
                    && ! $taken->contains(fn ($a) => $a->scheduled_at < $ends && $a->ends_at > $cursor);

                if ($free) {
                    $slots[$cursor->format('H:i')] = true;
                }

                $cursor->addMinutes($window->slot_minutes);
            }
        }

        ksort($slots);

        return array_keys($slots);
    }

    /**
     * Why the time row is empty, in the words of whatever is actually missing.
     * "No times" with nothing beside it sends people to look for a bug.
     */
    public function slotsNote(): ?string
    {
        if ($this->doctor_user_id === null) {
            return 'Choose a doctor to see the times they are free.';
        }
        if ($this->chosenDate() === null) {
            return 'Choose a day to see the times they are free.';
        }
        if ($this->openSlots() !== []) {
            return null;
        }

        $name = (string) (User::currentHospital()
            ->whereKey($this->doctor_user_id)
            ->value('name') ?: 'This doctor');
        $day = \Illuminate\Support\Carbon::parse((string) $this->chosenDate());

        $sits = \App\Models\DoctorSchedule::query()
            ->where('user_id', $this->doctor_user_id)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->exists();

        if (! $sits) {
            return $name.' has no availability on '.$day->format('l').'s. Set it on Doctor availability.';
        }

        return $name.' is fully booked on '.$day->format('D j M')
            .' at '.$this->duration_minutes.' minutes. Try a shorter appointment or another day.';
    }

    /** The doctor a filter is currently pinned to, so the picker can show it. */
    #[Computed]
    public function filterDoctor(): ?User
    {
        return $this->doctor === '' ? null : User::currentHospital()->find((int) $this->doctor);
    }

    /** @return array<string, string> */
    #[Computed]
    public function sources(): array
    {
        return AppointmentSource::options();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return AppointmentStatus::options();
    }

    /**
     * Everything both readings of the diary are narrowed by.
     *
     * The list and the calendar answer the same question in two shapes; one
     * place to filter is what stops them quietly disagreeing about what is in
     * the diary. The calendar deliberately ignores `date`, because the week it
     * draws IS the date.
     *
     * @return Builder<Appointment>
     */
    private function filtered(): Builder
    {
        return Appointment::with(['patient', 'doctor', 'room'])
            ->when($this->doctor !== '', fn (Builder $q) => $q->where('doctor_user_id', (int) $this->doctor))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('patient_no', 'like', "%{$this->search}%")
            ));
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->doctor !== '' || $this->status !== '' || $this->date !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'doctor', 'status', 'date']);
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('viewAny', Appointment::class);

        return view('livewire.appointments.index', [
            'rows' => $this->view === 'calendar' ? null : $this->listRows(),
            'date' => $this->date,
        ])->title('Appointments');
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int,Appointment> */
    private function listRows(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->filtered()
            ->when($this->date !== '', fn (Builder $q) => $q->forDate($this->date));

        /** @var Builder<Appointment> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q
            // One day: in the order of the day. The whole diary: what is COMING
            // is what somebody opened this page for, so the future comes first
            // with the soonest at the top, and the past follows it most-recent
            // first. Three clauses, one statement, and portable — `CASE` sorts
            // the same on MySQL and SQLite.
            ->when($this->date !== '', fn (Builder $inner) => $inner->orderBy('scheduled_at'))
            ->when($this->date === '', fn (Builder $inner) => $inner->orderByRaw(
                'CASE WHEN scheduled_at >= ? THEN 0 ELSE 1 END, '.
                'CASE WHEN scheduled_at >= ? THEN scheduled_at END ASC, '.
                'scheduled_at DESC',
                [$now = now(), $now],
            )));

        return $sorted->paginate($this->perPage);
    }
}
