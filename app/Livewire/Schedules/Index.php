<?php

namespace App\Livewire\Schedules;

use App\Enums\Weekday;
use App\Http\Requests\DoctorScheduleRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\WithTable;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\DoctorScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Doctor availability — the week a hospital actually runs.
 *
 * Two readings of one set of windows:
 *
 *  - ROSTER, the default: one row per doctor, seven columns, every window in
 *    its day. This is the question a scheduler has ("who sits on Thursday?"),
 *    and a flat list could not answer it — one doctor sitting every day was
 *    seven near-identical rows, and twenty doctors were a hundred and forty.
 *  - LIST: the same windows one per row, sortable, for editing in bulk.
 *
 * Three things the old page could not tell you, all of which decide whether
 * appointments can be booked at all:
 *
 *  - which doctors have NO availability (they cannot be booked, and nothing
 *    said so);
 *  - which days of the week nobody covers;
 *  - which windows OVERLAP. Booking walks a doctor's windows and takes the
 *    first that contains the requested time (AppointmentService::assertBookable),
 *    so two overlapping windows with different slot lengths make bookings fail
 *    against whichever was found first — an error about slot alignment that
 *    points at nothing the person can see.
 *
 * Validation is the shared DoctorScheduleRequest::rulesFor(). No navigation.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    // `save` is overridden below to fan a repeating answer out into one window
    // per day; the trait's own version still does the single-day case.
    use AuthorizesRequests, WithTable;
    use CrudModal {
        save as saveOneWindow;
    }

    /** Answers that mean several days at once, expanded when the form is saved. */

    /** How the week is read. */
    public const VIEWS = ['roster', 'list'];

    #[Url(history: true, except: 'roster')]
    public string $view = 'roster';

    /** '' = every day; otherwise a Weekday value as a string. */
    #[Url(history: true, except: '')]
    public string $day = '';

    /** '' = both; 'active' = bookable; 'off' = switched off. */
    #[Url(history: true, except: '')]
    public string $state = '';

    /** Show only the doctors with nothing on the roster at all. */
    #[Url(history: true, except: false)]
    public bool $unrostered = false;

    public ?int $user_id = null;

    public ?int $room_id = null;

    /**
     * A single day is its integer; the three repeating answers are words. Both
     * come off the same <select>, so the property has to hold either — and a
     * real day is normalised straight back to an int so every rule and query
     * below it sees exactly what it always saw.
     */
    public int|string|null $weekday = null;

    public ?string $start_time = null;

    public ?string $end_time = null;

    public int $slot_minutes = 30;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', DoctorSchedule::class);
    }

    /** Both readings are of the same filtered set, so a filter change resets both. */
    protected function resetsPage(): array
    {
        return ['view', 'day', 'state', 'unrostered'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['weekday', 'start_time', 'end_time', 'slot_minutes', 'is_active'];
    }

    public function updatedUnrostered(): void
    {
        if ($this->unrostered) {
            $this->view = 'roster';
        }
    }

    public function updatedView(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'roster';
        }
    }

    public function booted(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'roster';
        }
        if ($this->day !== '' && ! in_array((int) $this->day, array_column(Weekday::cases(), 'value'), true)) {
            $this->day = '';
        }
        if (! in_array($this->state, ['', 'active', 'off'], true)) {
            $this->state = '';
        }
    }

    /** Clear every filter at once — the way back from an empty table. */
    public function clearFilters(): void
    {
        $this->reset(['search', 'day', 'state', 'unrostered']);
        $this->resetPage();
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return DoctorSchedule::class;
    }

    protected function formFields(): array
    {
        return ['user_id', 'room_id', 'weekday', 'start_time', 'end_time', 'slot_minutes', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Availability window';
    }

    protected function defaults(): array
    {
        return ['slot_minutes' => 30, 'is_active' => true];
    }

    /**
     * Add a window into the cell that was clicked.
     *
     * On the roster, the empty cell IS the question — "Dr Kasujja, Thursday,
     * nothing" — so clicking it should not then ask which doctor and which day.
     */
    public function addFor(int $doctorId, int $weekday): void
    {
        $this->create();

        if (User::currentHospital()->clinicians()->whereKey($doctorId)->exists()) {
            $this->user_id = $doctorId;
        }
        if (in_array($weekday, array_column(Weekday::cases(), 'value'), true)) {
            $this->weekday = $weekday;
        }
    }

    public function updatedWeekday(mixed $value): void
    {
        if (is_numeric($value)) {
            $this->weekday = (int) $value;
        }
    }

    /** Switch one window on or off without opening the dialog for it. */
    public function toggleActive(int $id): void
    {
        $window = DoctorSchedule::findOrFail($id);
        $this->authorize('update', $window);

        $window->is_active = ! $window->is_active;
        $window->save();

        unset($this->facts);

        $this->dispatch('toast', type: 'success', message: $window->is_active
            ? 'Window switched on — it can be booked again.'
            : 'Window switched off — nothing new can be booked into it.');
    }

    protected function rules(): array
    {
        $rules = DoctorScheduleRequest::rulesFor($this->editingId);

        // The shared request knows one day. This dialog also accepts the three
        // repeating answers, which it expands itself — editing an existing
        // window is still one day, because a window IS one day.
        if ($this->editingId === null) {
            $rules['weekday'] = ['required', Rule::in(array_merge(
                [...DoctorScheduleService::repeatingAnswers()],
                array_map(fn (Weekday $d) => (string) $d->value, Weekday::cases()),
            ))];
        }

        return $rules;
    }

    /**
     * Save, fanning a repeating answer out into one window per day.
     *
     * A day that already has an identical window is skipped rather than
     * refused: "every day" on a doctor who already sits on Monday should add
     * the other six, not fail because of the one.
     */
    public function save(): void
    {
        if (is_numeric($this->weekday)) {
            $this->weekday = (int) $this->weekday;
        }

        $days = $this->editingId === null ? app(DoctorScheduleService::class)->daysMeant((string) $this->weekday) : [];

        if (count($days) <= 1) {
            $this->saveOneWindow();
            unset($this->facts);

            return;
        }

        $this->authorize('create', DoctorSchedule::class);
        $data = $this->validate();

        ['added' => $added, 'skipped' => $skipped] = app(DoctorScheduleService::class)->addWindows($data, $days);

        $this->showForm = false;
        $this->resetForm();
        unset($this->facts);

        $this->dispatch('toast', type: $added > 0 ? 'success' : 'info', message: $added === 0
            ? 'Every one of those days already has this window.'
            : $added.' '.\Illuminate\Support\Str::plural('window', $added).' added'
                .($skipped > 0 ? ', '.$skipped.' already there.' : '.'));
    }

    /** Times are stored as H:i:s but edited as H:i (the rule is date_format:H:i). */
    protected function fillExtra(Model $model): void
    {
        if ($model instanceof DoctorSchedule) {
            $this->start_time = substr($model->start_time, 0, 5);
            $this->end_time = substr($model->end_time, 0, 5);
        }
    }

    // Doctors and rooms were two uncapped `->get()`s on every render, drawn
    // into two <select>s. They are <livewire:ui.select-search> now, which
    // searches, caps its results and browses on focus (docs/visits.md).

    /**
     * The seven days, and the three ways a clinic actually describes them.
     *
     * A doctor who sits every weekday morning had to add five identical windows
     * one at a time, and a doctor who sits every day, seven. The groups at the
     * top of the list fan out on save — one dialog, one answer, all the days it
     * means.
     *
     * @return array<string,array<int|string,string>> optgroup => value => label
     */
    #[Computed]
    public function weekdayOptions(): array
    {
        return [
            'Repeating' => [
                DoctorScheduleService::EVERY_DAY => 'Every day',
                DoctorScheduleService::WEEKDAYS => 'Weekdays · Mon to Fri',
                DoctorScheduleService::WEEKEND => 'Weekend · Sat & Sun',
            ],
            'One day' => Weekday::options(),
        ];
    }

    /**
     * The shifts a clinic runs, as one click each.
     *
     * A shift is a start AND an end; offering them as two separate lists asks
     * the same question twice.
     *
     * @return array<string,array<string,string>>
     */
    #[Computed]
    public function shifts(): array
    {
        return [
            'Morning · 8–12' => ['start_time' => '08:00', 'end_time' => '12:00'],
            'Afternoon · 2–5' => ['start_time' => '14:00', 'end_time' => '17:00'],
            'Full day · 8–5' => ['start_time' => '08:00', 'end_time' => '17:00'],
            'Evening · 5–8' => ['start_time' => '17:00', 'end_time' => '20:00'],
        ];
    }

    // ── What the week adds up to ─────────────────────────────────────────

    /**
     * Everything the headline figures, the coverage strip and the overlap
     * flags are derived from, read once.
     *
     * Deliberately the whole hospital rather than the filtered page: "four
     * doctors have no availability" is a fact about the roster, and a figure
     * that changed as you typed in the search box would be telling you about
     * your search instead. The set is bounded by doctors × 7 days × the few
     * windows a day can hold, which is why it can be totalled in PHP.
     *
     * @return array{
     *     doctors:int, rostered:int, unrostered:int, hours:float, slots:int,
     *     windows:int, off:int, coverage:array<int,int>, conflicts:array<int,bool>
     * }
     */
    #[Computed]
    public function facts(): array
    {
        return app(DoctorScheduleService::class)->facts();
    }

    /**
     * How long a window runs, in minutes. "08:00:00"–"17:00:00" is 540.
     *
     * The view asks for this rather than working it out: arithmetic on a time
     * string is not markup, and a `@php` block big enough to hold it stops
     * Blade compiling the rest of the file.
     */
    public function spanOf(DoctorSchedule $window): int
    {
        return max(0, DoctorScheduleService::minutesOf($window->end_time) - DoctorScheduleService::minutesOf($window->start_time));
    }

    /** How many appointments one window actually holds. */
    public function slotsIn(DoctorSchedule $window): int
    {
        return $window->slot_minutes > 0 ? intdiv($this->spanOf($window), $window->slot_minutes) : 0;
    }

    /** The hours a doctor is bookable in a week, over the windows now shown. */
    public function hoursOf(User $doctor): float
    {
        $minutes = 0;

        foreach ($doctor->schedules as $window) {
            if ($window->is_active) {
                $minutes += $this->spanOf($window);
            }
        }

        return round($minutes / 60, 1);
    }

    /**
     * One doctor's windows, filed under the day they sit on.
     *
     * @return array<int,list<DoctorSchedule>>
     */
    public function weekOf(User $doctor): array
    {
        $week = [];

        foreach ($doctor->schedules as $window) {
            $week[$window->weekday->value][] = $window;
        }

        return $week;
    }

    /** Monday-first, because that is how a week is read. @return array<int,string> */
    #[Computed]
    public function days(): array
    {
        return Weekday::options();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->day !== '' || $this->state !== '' || $this->unrostered;
    }

    // ── The two readings ─────────────────────────────────────────────────

    /**
     * The window filters, applied the same way whichever view is drawing.
     *
     * The list narrows a query; the roster narrows a relation it is eager
     * loading. Both answer `when()` and `where()`, and the point of one method
     * is that the two views cannot drift into filtering differently.
     *
     * @template TQuery of Builder<DoctorSchedule>|Relation<DoctorSchedule, User, \Illuminate\Database\Eloquent\Collection<int, DoctorSchedule>>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function filterWindows(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->when($this->day !== '', fn ($q) => $q->where('weekday', (int) $this->day))
            ->when($this->state !== '', fn ($q) => $q->where('is_active', $this->state === 'active'));
    }

    public function render()
    {
        $this->authorize('viewAny', DoctorSchedule::class);

        return view('livewire.schedules.index', $this->view === 'list'
            ? ['rows' => $this->windowRows(), 'roster' => null]
            : ['rows' => null, 'roster' => $this->rosterRows()])
            ->title('Doctor availability');
    }

    /**
     * ROSTER: a page of doctors, each carrying the windows that survived the
     * filters. It paginates over DOCTORS — the row is the doctor, and a doctor
     * split across two pages would be a roster you cannot read.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int,User>
     */
    private function rosterRows(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return User::currentHospital()
            ->clinicians()
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->unrostered, fn (Builder $q) => $q->whereDoesntHave('schedules'))
            ->with(['schedules' => fn ($q) => $this->filterWindows($q)
                ->with('room')->orderBy('weekday')->orderBy('start_time')])
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    /**
     * LIST: one window per row, sortable.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int,DoctorSchedule>
     */
    private function windowRows(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = DoctorSchedule::query()
            ->with(['doctor', 'room'])
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('doctor',
                fn (Builder $u) => $u->where('name', 'like', "%{$this->search}%")));
        /** @var Builder<DoctorSchedule> $sorted */
        $sorted = $this->applySort(
            $this->filterWindows($query),
            fn (Builder $q) => $q->orderBy('user_id')->orderBy('weekday')->orderBy('start_time'),
        );

        return $sorted->paginate($this->perPage);
    }
}
