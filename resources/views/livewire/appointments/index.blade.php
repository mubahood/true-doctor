<div>
  <h1 class="sr-only">Appointments</h1>

  @php($targets = 'search,gotoPage,previousPage,nextPage,perPage,date,doctor,status,view,sortBy,shiftWeek,thisWeek,clearFilters,advance')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-seg" role="group" aria-label="How to read the diary">
      <button type="button" @class(['tb-seg-btn', 'is-on' => $view === 'list']) wire:click="$set('view', 'list')">
        <i class="fas fa-list" aria-hidden="true"></i> List
      </button>
      <button type="button" @class(['tb-seg-btn', 'is-on' => $view === 'calendar']) wire:click="$set('view', 'calendar')">
        <i class="fas fa-calendar-days" aria-hidden="true"></i> Calendar
      </button>
    </div>

    @if($view === 'calendar')
      {{-- The calendar's date IS the week it draws, so it is moved a week at a
           time rather than typed. --}}
      <div class="tb-weeknav" role="group" aria-label="Which week">
        <button type="button" class="tb-weeknav-btn" wire:click="shiftWeek(-1)" aria-label="Previous week"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="tb-weeknav-label" wire:click="thisWeek" title="Back to this week">
          {{ $this->weekStart()->format('j M') }} – {{ $this->weekStart()->copy()->addDays(6)->format('j M Y') }}
        </button>
        <button type="button" class="tb-weeknav-btn" wire:click="shiftWeek(1)" aria-label="Next week"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
      </div>
    @else
      <input type="date" wire:model.live="date" class="tb-input" aria-label="Diary date">
    @endif

    {{-- A searchable picker, not a <select> of every doctor in the hospital:
         this used to load the whole users table on every render, and a list of
         sixty names in a dropdown is unusable anyway. --}}
    <div class="tb-filter-pick">
      <livewire:ui.select-search resource="doctors" name="doctor" :selected="$this->filterDoctor?->id"
        placeholder="All doctors" :key="'appt-filter-doc-'.$doctor" />
    </div>
    <select wire:model.live="status" class="tb-select" aria-label="Filter by status"><option value="">All statuses</option>
      @foreach($this->statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search patient…" aria-label="Search appointments by patient">
    </div>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      <a wire:navigate href="{{ route('admin.appointments.queue') }}" class="btn-tb"><i class="fas fa-users-line" aria-hidden="true"></i> Queue</a>
      @can('create', \App\Models\Appointment::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> Book</button>
      @endcan
    </div>
  </div>

@if($view === 'calendar')
  {{-- ── The week ──────────────────────────────────────────────────
       A diary is a week before it is a list. Seven columns, each
       appointment at its time, and a click opens the same dialog the
       row menu does. --}}
  <div class="tb-card tb-cal" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}">
    @foreach($this->week as $day)
      <div @class(['tb-cal-day', 'is-today' => $day['date']->isToday(), 'is-past' => $day['date']->isPast() && ! $day['date']->isToday()])>
        <div class="tb-cal-head">
          <span class="tb-cal-dow">{{ $day['date']->format('D') }}</span>
          <span class="tb-cal-date">{{ $day['date']->format('j M') }}</span>
          @if($day['appointments']->isNotEmpty())
            <span class="tb-cal-n">{{ $day['appointments']->count() }}</span>
          @endif
        </div>

        <div class="tb-cal-body">
          @forelse($day['appointments'] as $a)
            <button type="button" wire:key="cal-{{ $a->id }}"
                    @class(['tb-cal-appt', 'is-'.$a->status->value])
                    wire:click="peek({{ $a->id }})"
                    title="{{ $a->scheduled_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }} · {{ $a->patient?->full_name }} · {{ $a->doctor?->name }} · {{ $a->status->label() }}">
              <span class="tb-cal-time">{{ $a->scheduled_at->format('H:i') }}</span>
              <span class="tb-cal-who">{{ $a->patient?->full_name ?? '—' }}</span>
              <span class="tb-cal-doc">{{ $a->doctor?->name ?? '—' }}</span>
            </button>
          @empty
            <span class="tb-cal-free">—</span>
          @endforelse
        </div>
      </div>
    @endforeach
  </div>
  <p class="tb-hint">Showing the week of {{ $this->weekStart()->format('j M Y') }}. Click an appointment to see it.</p>
@else
  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">The appointment diary</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="scheduled_at" :sort-field="$sortField" :sort-dir="$sortDir">When</x-ui.th-sort>
        <th>Patient</th>
        <th>Doctor</th>
        <th>Room</th>
        <x-ui.th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($rows as $a)
          <tr wire:key="appt-{{ $a->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            {{-- The day, not only the hour. With no date filter the list spans
                 the whole diary, and "09:30–10:15" on its own does not say
                 which 09:30 — the commonest reading mistake this page could
                 invite. --}}
            <td class="tb-nowrap">
              {{-- Opens the record over the list rather than instead of it —
                   the full page is still one click further, in the menu. --}}
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $a->id }})"
                      title="See this appointment">
                <span class="tb-when-day">{{ $a->scheduled_at->isToday() ? 'Today' : ($a->scheduled_at->isTomorrow() ? 'Tomorrow' : $a->scheduled_at->format('D j M')) }}</span>
                <span class="tb-when-time mono">{{ $a->scheduled_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }}</span>
              </button>
            </td>
            <td class="tb-fw-500">
              {{ $a->patient?->full_name ?? '—' }}
              @if($a->reason)<span class="tb-row-sub">{{ \Illuminate\Support\Str::limit($a->reason, 48) }}</span>@endif
            </td>
            <td class="muted">{{ $a->doctor?->name ?? '—' }}</td>
            <td class="muted">{{ $a->room?->name ?? '—' }}</td>
            <td><x-ui.badge :tone="$a->status->badge()">{{ $a->status->label() }}</x-ui.badge></td>
            <td class="tb-text-right tb-nowrap">
              {{-- The one thing the desk presses on this row.

                   Once the patient is in front of the doctor that is not a
                   status at all — it is writing up what was done. Offering
                   "In progress" first and the report only afterwards is the
                   bare button this page was rebuilt to get rid of, so a
                   checked-in appointment goes straight to the report;
                   recordOutcome walks it through In progress itself. --}}
              @can('update', $a)
                @php($arrived = in_array($a->status, [\App\Enums\AppointmentStatus::CheckedIn, \App\Enums\AppointmentStatus::InProgress], true))
                @php($next = $a->status->transitionsTo())

                @if($arrived)
                  <button type="button" class="tb-nextbtn is-record" wire:click="openOutcome({{ $a->id }})"
                          title="Write the report and charge what was provided">
                    <i class="fas fa-file-pen" aria-hidden="true"></i> Record outcome
                  </button>
                @elseif(isset($next[0]) && ! in_array($next[0], [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow], true))
                  <button type="button" class="tb-nextbtn"
                          wire:click="advance({{ $a->id }}, '{{ $next[0]->value }}')"
                          title="Move {{ $a->patient?->full_name }} to {{ $next[0]->label() }}">
                    {{ $next[0]->label() }}
                  </button>
                @endif
              @endcan

              <x-ui.actions-menu label="Actions for {{ $a->patient?->full_name ?? 'this appointment' }}">
                <button type="button" role="menuitem" wire:click="peek({{ $a->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                <a role="menuitem" wire:navigate href="{{ route('admin.appointments.show', $a) }}"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record</a>
                @can('update', $a)
                  @unless($a->status->isTerminal())
                    <button type="button" role="menuitem" wire:click="edit({{ $a->id }})"><i class="fas fa-pen" aria-hidden="true"></i> Change time or room</button>
                  @endunless
                  @php($moves = $a->status->transitionsTo())
                  @if($moves)
                    <hr>
                    <span class="tb-menu-sec">Move to</span>
                    @foreach($moves as $to)
                      <button type="button" role="menuitem"
                              @class(['danger' => in_array($to, [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow], true)])
                              wire:click="advance({{ $a->id }}, '{{ $to->value }}')">
                        @if($to === \App\Enums\AppointmentStatus::Completed)
                          <i class="fas fa-file-pen" aria-hidden="true"></i> Record outcome
                        @else
                          {{ $to->label() }}
                        @endif
                      </button>
                    @endforeach
                  @endif
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="7">
            <x-ui.empty icon="fa-calendar-day" noun="appointments"
              :filtered="$this->hasFilters()"
              :message="! $this->hasFilters() ? 'Nothing is booked yet.' : null"
              wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}
@endif

  {{-- ── Slide-over: book ──────────────────────────────────────── --}}
  <x-ui.modal size="xl" show="showForm" :title="$editingId ? 'Change appointment' : 'Book appointment'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        {{-- An appointment belongs to an attendance, not to a name out of the
             register. The commonest booking by far is "come back in two weeks",
             said in the consulting room — so the visit comes first, and it
             brings the patient, the doctor and the department with it. A cold
             booking over the telephone leaves it empty and picks the patient
             beside it instead (docs/visits.md).

             The two sit on one line because they are one question: which
             attendance this follows, and therefore who it is for. --}}
        <div class="tb-form-grid">
          <x-ui.field label="Following up on a visit" name="origin_visit_id"
                      hint="Leave empty for a new booking.">
            <livewire:ui.select-search resource="visits" name="origin_visit_id" :selected="$origin_visit_id"
              placeholder="Search open visits…"
              :key="'appt-visit-'.$formNonce" />
          </x-ui.field>

          @if($this->originVisit)
            {{-- Filled from the visit and shown, not offered: an appointment whose
                 origin says one person and whose patient says another is a record
                 nobody can act on. --}}
            <x-ui.field label="Patient" name="patient_id"
                        hint="From {{ $this->originVisit->visit_no }}.">
              <input type="text" class="tb-input" disabled
                     value="{{ $this->originVisit->patient?->full_name ?? '—' }}">
            </x-ui.field>
          @else
            <x-ui.field label="Patient" name="patient_id" required>
              <livewire:ui.select-search resource="patients" name="patient_id" :selected="$patient_id"
                placeholder="Search name, number or phone…"
                :key="'appt-patient-'.$formNonce" />
            </x-ui.field>
          @endif
        </div>

        <div class="tb-form-grid">
          {{-- Department first, then the doctor narrowed to it — the same
               cascade the visit form uses (docs/visits.md). --}}
          <x-ui.field label="Department" name="department_id"
                      hint="Choosing one narrows the doctor box to it.">
            <livewire:ui.select-search resource="departments" name="department_id" :selected="$department_id"
              placeholder="Search departments…" :key="'appt-dept-'.$formNonce" />
          </x-ui.field>
          <x-ui.field label="Doctor" name="doctor_user_id" required
                      :hint="! $department_id ? null
                          : ($this->departmentHasDoctors
                              ? 'Narrowed to this department'
                              : 'No doctors are attached to this department, so every doctor is offered')">
            <livewire:ui.select-search resource="doctors" name="doctor_user_id" :selected="$doctor_user_id"
              :scope="$department_id" placeholder="Search doctors…"
              :key="'appt-doc-'.$formNonce.'-'.($department_id ?? 0)" />
          </x-ui.field>
          <x-ui.field label="Source" for="appt-source" name="source" required>
            <select id="appt-source" wire:model="source" class="tb-select">
              <option value="">— select —</option>
              @foreach($this->sources as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
            </select>
          </x-ui.field>
          {{-- The length comes BEFORE the time, because it decides which times
               fit: a 15-minute gap is free for a 15-minute appointment and not
               for an hour one. --}}
          <x-ui.field label="How long" for="appt-duration" name="duration_minutes" required>
            <x-ui.suggestions set="duration_minutes" :current="$duration_minutes"
                              :options="['15' => '15 min', '30' => '30 min', '45' => '45 min', '60' => '1 hour', '90' => '1½ hours']" />
            <input id="appt-duration" type="number" wire:model.live.debounce.500ms="duration_minutes" class="tb-input tb-input-slim"
                   min="5" max="480" required aria-label="Duration in minutes">
          </x-ui.field>
        </div>

        {{-- ── When ────────────────────────────────────────────────────
             "18/09/2026, 20:46" typed into a datetime box is three chances to
             get it wrong, and it never said the doctor does not work that day
             or that the hour is already taken. Two questions instead: which
             day, then which of that day's free times — and the times offered
             are generated the way the booking service checks them, so one that
             is offered is one that books. --}}
        <div class="tb-when" wire:key="when-{{ $formNonce }}">
          <x-ui.field label="Which day" name="scheduled_at" required>
            <div class="tb-daystrip" role="group" aria-label="Which day">
              @foreach($this->dayChoices as $choice)
                <button type="button"
                        @class(['tb-day', 'is-on' => $choice['date'] === $this->chosenDate(), 'is-away' => ! $choice['sits']])
                        wire:click="chooseDay('{{ $choice['date'] }}')"
                        title="{{ $choice['sits'] ? $choice['label'] : 'This doctor has no availability on this day' }}">
                  <span class="tb-day-name">{{ $choice['label'] }}</span>
                  <span class="tb-day-sub">{{ $choice['sub'] }}</span>
                </button>
              @endforeach
            </div>
            {{-- Still a real datetime box underneath, for the booking that is
                 months out or at an hour the roster does not describe. --}}
            <details class="tb-when-exact">
              <summary>Some other date or time</summary>
              <input id="appt-when" type="datetime-local" wire:model.live="scheduled_at" class="tb-input" required>
            </details>
          </x-ui.field>

          <x-ui.field label="What time" name="scheduled_at">
            @if($this->openSlots)
              <div class="tb-slots" role="group" aria-label="Times this doctor is free">
                @foreach($this->openSlots as $slot)
                  <button type="button" @class(['tb-slot', 'is-on' => $slot === $this->chosenTime()])
                          wire:click="chooseTime('{{ $slot }}')">{{ $slot }}</button>
                @endforeach
              </div>
              <p class="tb-hint">{{ count($this->openSlots) }} free at {{ $duration_minutes }} minutes.</p>
            @else
              <p class="tb-slots-none">{{ $this->slotsNote() }}</p>
            @endif
          </x-ui.field>
        </div>

        <div class="tb-form-grid">
          <x-ui.field label="Room" name="room_id">
            <livewire:ui.select-search resource="rooms" name="room_id" :selected="$room_id"
              placeholder="Search rooms…" :key="'appt-room-'.$formNonce" />
          </x-ui.field>
        </div>
        <x-ui.field label="Reason" for="appt-reason" name="reason">
          <textarea id="appt-reason" wire:model="reason" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Book appointment' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> {{ $editingId ? 'Saving…' : 'Booking…' }}</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  {{-- ── Quick view ────────────────────────────────────────────────
       Everything the detail page says, over the list rather than instead
       of it. Opening a whole page to answer "what is this one, again?"
       loses the place somebody is working down, and the answer is six
       lines long. --}}
  <x-ui.modal show="showPeek" size="md" autosaves
              :title="$this->peeked?->patient?->full_name ?? 'Appointment'">
    @if($this->peeked)
      @php($a = $this->peeked)
      <div class="tb-modal-body">
        <div class="tb-peek-head">
          <div>
            <div class="tb-peek-when">{{ $a->scheduled_at->format('l j F Y') }}</div>
            <div class="tb-peek-time mono">{{ $a->scheduled_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }}
              <span class="muted">· {{ $a->duration_minutes }} min</span></div>
          </div>
          <x-ui.badge :tone="$a->status->badge()">{{ $a->status->label() }}</x-ui.badge>
        </div>

        <dl class="tb-peek-facts">
          <dt>Patient</dt>
          <dd>
            @if($a->patient)
              <a class="rowlink" wire:navigate href="{{ route('admin.patients.show', $a->patient) }}">{{ $a->patient->full_name }}</a>
              <span class="mono muted">{{ $a->patient->patient_no }}</span>
            @else — @endif
          </dd>

          <dt>Doctor</dt><dd>{{ $a->doctor?->name ?? '—' }}</dd>
          <dt>Department</dt><dd>{{ $a->department?->name ?? '—' }}</dd>
          <dt>Room</dt><dd>{{ $a->room?->name ?? '—' }}</dd>
          <dt>Booked as</dt><dd>{{ $a->source->label() }}</dd>

          @if($a->originVisit)
            <dt>Follows</dt>
            <dd><a class="rowlink" wire:navigate href="{{ route('admin.visits.show', $a->originVisit) }}">{{ $a->originVisit->visit_no }}</a></dd>
          @endif

          @if($a->reason)<dt>Reason</dt><dd>{{ $a->reason }}</dd>@endif
          @if($a->visit)
            {{-- Where what happened at it was written down. --}}
            <dt>Seen in</dt>
            <dd><a class="rowlink" wire:navigate href="{{ route('admin.visits.show', $a->visit) }}">{{ $a->visit->visit_no }}</a></dd>
          @endif
          @if($a->cancel_reason)<dt>Why it ended</dt><dd class="tb-peek-bad">{{ $a->cancel_reason }}</dd>@endif
        </dl>

        {{-- What has happened to it, which is the part a list cannot show at
             all and the commonest reason for opening the record. --}}
        @if($a->history->isNotEmpty())
          <div class="tb-peek-sec">Trail</div>
          <ul class="tb-peek-trail">
            @foreach($a->history->sortByDesc('created_at') as $h)
              <li wire:key="peek-h-{{ $h->id }}">
                <span class="tb-peek-step">{{ $h->from_status?->label() ?? 'Booked' }} → {{ $h->to_status->label() }}</span>
                <span class="tb-peek-meta">{{ $h->created_at?->format('j M · H:i') }}@if($h->changedBy) · {{ $h->changedBy->name }}@endif</span>
                @if($h->note)<span class="tb-peek-note">{{ $h->note }}</span>@endif
              </li>
            @endforeach
          </ul>
        @endif
      </div>

      <div class="tb-modal-foot tb-peek-foot">
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.appointments.show', $a) }}">
          <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
        </a>
        <span class="tb-peek-gap"></span>
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
        @can('update', $a)
          @unless($a->status->isTerminal())
            <button type="button" class="btn-tb" wire:click="edit({{ $a->id }})">
              <i class="fas fa-calendar-day" aria-hidden="true"></i> Change
            </button>
            @php($arrived = in_array($a->status, [\App\Enums\AppointmentStatus::CheckedIn, \App\Enums\AppointmentStatus::InProgress], true))
            @php($next = $a->status->transitionsTo())
            @if($arrived)
              <button type="button" class="btn-tb btn-tb-primary" wire:click="openOutcome({{ $a->id }})">
                <i class="fas fa-file-pen" aria-hidden="true"></i> Record outcome
              </button>
            @elseif(isset($next[0]) && ! in_array($next[0], [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow], true))
              <button type="button" class="btn-tb btn-tb-primary" wire:click="advance({{ $a->id }}, '{{ $next[0]->value }}')">
                <i class="fas fa-check" aria-hidden="true"></i> {{ $next[0]->label() }}
              </button>
            @endif
          @endunless
        @endcan
      </div>
    @endif
  </x-ui.modal>

  @include('livewire.appointments.partials.act-dialogs')

</div>
