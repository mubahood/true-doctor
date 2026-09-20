<div>
  <h1 class="sr-only">Doctor availability</h1>

  @php
    $targets = 'search,view,day,state,unrostered,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters,toggleActive';
    $facts = $this->facts;
  @endphp

  {{-- What the week comes to. A roster is only worth reading against what it
       is MISSING, so the doctors nobody can book sit here as a headline rather
       than as an absence you would have to notice. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-user-doctor" :value="$facts['rostered']" label="Doctors on the roster"
                 :sub="$facts['doctors'].' '.\Illuminate\Support\Str::plural('doctor', $facts['doctors']).' in all'" />
    <x-dash.stat icon="fa-hourglass-half" :value="$facts['hours'].' h'" label="Clinic hours a week"
                 :sub="$facts['windows'].' '.\Illuminate\Support\Str::plural('window', $facts['windows'])
                       .($facts['off'] > 0 ? ', '.$facts['off'].' switched off' : '')" />
    <x-dash.stat icon="fa-calendar-check" :value="number_format($facts['slots'])" label="Appointment slots a week"
                 sub="What the roster can hold" />
    {{-- A link, not a click handler on a card: `unrostered` is in the URL, so
         the shortcut can be a real one that the keyboard reaches. --}}
    <x-dash.stat icon="fa-user-slash" :tone="$facts['unrostered'] > 0 ? 'warn' : 'ok'"
                 :value="$facts['unrostered']" label="Cannot be booked"
                 :sub="$facts['unrostered'] > 0 ? 'No availability set — show them' : 'Every doctor is bookable'"
                 :href="$facts['unrostered'] > 0 ? route('admin.schedules.index', ['unrostered' => 1]) : null" />
  </div>

  {{-- Which days the hospital is actually open, by how many doctors. A day
       with nobody on it takes no appointments at all, and nothing used to say
       so until somebody tried to book one. --}}
  <div class="tb-coverage" role="group" aria-label="Doctors available each day">
    <span class="tb-coverage-lead">Cover</span>
    @foreach($this->days as $val => $label)
      @php($n = $facts['coverage'][$val] ?? 0)
      <button type="button" @class(['tb-cov-day', 'is-none' => $n === 0, 'is-on' => (string) $val === $day])
              wire:click="$set('day', '{{ (string) $val === $day ? '' : $val }}')"
              title="{{ $n === 0 ? 'Nobody is available on '.$label : $n.' '.\Illuminate\Support\Str::plural('doctor', $n).' available on '.$label }}">
        <span class="tb-cov-name">{{ \Illuminate\Support\Str::substr($label, 0, 3) }}</span>
        <span class="tb-cov-n">{{ $n === 0 ? '—' : $n }}</span>
      </button>
    @endforeach
  </div>

  {{-- ── Toolbar ───────────────────────────────────────────────── --}}
  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-seg" role="group" aria-label="How to read the week">
      <button type="button" @class(['tb-seg-btn', 'is-on' => $view === 'roster']) wire:click="$set('view', 'roster')">
        <i class="fas fa-table-cells" aria-hidden="true"></i> Roster
      </button>
      <button type="button" @class(['tb-seg-btn', 'is-on' => $view === 'list']) wire:click="$set('view', 'list')">
        <i class="fas fa-list" aria-hidden="true"></i> List
      </button>
    </div>

    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search doctor…" aria-label="Search availability by doctor">
    </div>

    <select wire:model.live="day" class="tb-select" aria-label="Filter by day">
      <option value="">Any day</option>
      @foreach($this->days as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <select wire:model.live="state" class="tb-select" aria-label="Filter by whether the window is on">
      <option value="">On and off</option>
      <option value="active">Bookable only</option>
      <option value="off">Switched off only</option>
    </select>

    <label class="tb-check-group" title="Doctors with no availability at all">
      <input type="checkbox" wire:model.live="unrostered"> Not rostered
    </label>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\DoctorSchedule::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add window</button>@endcan
    </div>
  </div>

  {{-- Overlapping windows are not a cosmetic problem: booking takes the FIRST
       window that contains the requested time, so the second one's slot length
       is quietly ignored and the person booking gets an alignment error about
       a number they cannot see anywhere. --}}
  @if($facts['conflicts'])
    <div class="tb-warnbar">
      <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
      <span><b>{{ count($facts['conflicts']) }}</b> {{ \Illuminate\Support\Str::plural('window', count($facts['conflicts'])) }} overlap another on the same day.
        Booking uses whichever it finds first, so the other one's slot length is ignored. They are marked below.</span>
    </div>
  @endif

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}">
  @if($view === 'roster')
    {{-- ── Roster: one row per doctor, one column per day ──────────── --}}
    <div class="tb-table-wrap"><table class="tb-table tb-roster">
      <caption class="sr-only">Every doctor's week</caption>
      <thead>
        <tr>
          <th class="tb-serial">#</th><th class="tb-roster-who">Doctor</th>
          @foreach($this->days as $val => $label)
            <th @class(['tb-roster-day', 'is-dim' => ($facts['coverage'][$val] ?? 0) === 0])>
              <span class="tb-roster-dayname">{{ \Illuminate\Support\Str::substr($label, 0, 3) }}</span>
            </th>
          @endforeach
          <th class="tb-text-right tb-nowrap">Week</th>
        </tr>
      </thead>
      <tbody>
        @forelse($roster as $doc)
          @php($week = $this->weekOf($doc))
          <tr wire:key="roster-{{ $doc->id }}">
            <x-ui.serial :rows="$roster" :loop="$loop" />
            <td class="tb-roster-who tb-fw-500">
              {{ $doc->name }}
              @if($doc->schedules->isEmpty())
                <span class="tb-roster-none">{{ $this->hasFilters() ? 'nothing matches' : 'not bookable' }}</span>
              @endif
            </td>

            @foreach($this->days as $val => $label)
              <td class="tb-roster-cell">
                @forelse($week[$val] ?? [] as $w)
                  @php($clash = isset($facts['conflicts'][$w->id]))
                  <button type="button" wire:key="w-{{ $w->id }}"
                          @class(['tb-win', 'is-off' => ! $w->is_active, 'is-clash' => $clash])
                          @can('update', $w) wire:click="edit({{ $w->id }})" @else disabled @endcan
                          title="{{ $doc->name }} · {{ $label }} {{ substr($w->start_time, 0, 5) }}–{{ substr($w->end_time, 0, 5) }} · {{ $w->slot_minutes }} min slots{{ $w->room ? ' · '.$w->room->name : '' }}{{ $w->is_active ? '' : ' · switched off' }}{{ $clash ? ' · overlaps another window' : '' }}">
                    <span class="tb-win-time">{{ substr($w->start_time, 0, 5) }}–{{ substr($w->end_time, 0, 5) }}</span>
                    {{-- The newline matters: Blade only sees a directive when
                         the character before `@` is not a word character, so
                         `…}}m@if(` would be left in the page as text. --}}
                    <span class="tb-win-meta">{{ $w->slot_minutes }}m
                      @if($clash)<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>@endif</span>
                  </button>
                @empty
                  @can('create', \App\Models\DoctorSchedule::class)
                    <button type="button" class="tb-win-add" wire:click="addFor({{ $doc->id }}, {{ $val }})"
                            title="Add a {{ $label }} window for {{ $doc->name }}"><i class="fas fa-plus" aria-hidden="true"></i><span class="sr-only">Add {{ $label }} window for {{ $doc->name }}</span></button>
                  @else
                    <span class="tb-win-empty" aria-hidden="true">·</span>
                  @endcan
                @endforelse
              </td>
            @endforeach

            <td class="tb-text-right tb-nowrap muted tb-small">
              @if($this->hoursOf($doc) > 0){{ $this->hoursOf($doc) }} h @else — @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="10">
            <x-ui.empty icon="fa-user-doctor" noun="doctors" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table></div>
    {{ $roster->links(data: $this->paginationData()) }}
  @else
    {{-- ── List: one window per row ────────────────────────────────── --}}
    <div class="tb-table-wrap"><table class="tb-table">
      <caption class="sr-only">Every availability window</caption>
      <thead>
        <tr>
          <th>Doctor</th>
          <x-ui.th-sort field="weekday" :sort-field="$sortField" :sort-dir="$sortDir">Day</x-ui.th-sort>
          <x-ui.th-sort field="start_time" :sort-field="$sortField" :sort-dir="$sortDir">From</x-ui.th-sort>
          <x-ui.th-sort field="end_time" :sort-field="$sortField" :sort-dir="$sortDir">To</x-ui.th-sort>
          <x-ui.th-sort field="slot_minutes" :sort-field="$sortField" :sort-dir="$sortDir">Slot</x-ui.th-sort>
          <th class="tb-text-right">Slots</th>
          <th>Room</th>
          <x-ui.th-sort field="is_active" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $r)
          @php($clash = isset($facts['conflicts'][$r->id]))
          <tr wire:key="sched-{{ $r->id }}">
            <td class="tb-fw-500">{{ $r->doctor?->name ?? '—' }}</td>
            <td>
              {{ $r->weekday->label() }}
              @if($clash)<i class="fas fa-triangle-exclamation tb-clash-mark" title="Overlaps another window on this day" aria-label="Overlaps another window on this day"></i>@endif
            </td>
            <td class="mono">{{ \Illuminate\Support\Str::of($r->start_time)->substr(0,5) }}</td>
            <td class="mono">{{ \Illuminate\Support\Str::of($r->end_time)->substr(0,5) }}</td>
            <td class="muted">{{ $r->slot_minutes }} min</td>
            {{-- How many appointments this window actually holds, which is the
                 figure a scheduler wants and nobody could work out from the
                 other three columns in their head. --}}
            <td class="tb-text-right muted">{{ $this->slotsIn($r) }}</td>
            <td class="muted">{{ $r->room?->name ?? '—' }}</td>
            <td>
              @can('update', $r)
                <button type="button" class="tb-badge-btn" wire:click="toggleActive({{ $r->id }})"
                        title="{{ $r->is_active ? 'Switch this window off' : 'Switch this window back on' }}">
                  <x-ui.badge :tone="$r->is_active ? 'active' : 'danger'">{{ $r->is_active ? 'Active' : 'Off' }}</x-ui.badge>
                </button>
              @else
                <x-ui.badge :tone="$r->is_active ? 'active' : 'danger'">{{ $r->is_active ? 'Active' : 'Off' }}</x-ui.badge>
              @endcan
            </td>
            <td class="tb-text-right tb-nowrap">
              @can('update', $r)<x-ui.icon-button label="Edit {{ $r->doctor?->name }} {{ $r->weekday->label() }} window" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
              @can('delete', $r)<x-ui.icon-button label="Remove {{ $r->doctor?->name }} {{ $r->weekday->label() }} window" icon="fa-trash" wire:click="delete({{ $r->id }})" wire:confirm="Remove this window?" />@endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="9"><x-ui.empty icon="fa-calendar-week" noun="availability windows" :filtered="$this->hasFilters()" wire:click="clearFilters" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
    {{ $rows->links(data: $this->paginationData()) }}
  @endif
  </div>

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit window' : 'Add availability window'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        {{-- A picker, not a <select> of every doctor: the list used to be an
             uncapped query on every render of the page. --}}
        <x-ui.field label="Doctor" name="user_id" required>
          <livewire:ui.select-search resource="doctors" name="user_id" :selected="$user_id"
            placeholder="Search doctors…" :key="'sch-doc-'.$formNonce" />
        </x-ui.field>
        {{-- "Every day" and "Weekdays" come first because they are what most
             availability actually is. A doctor who sits every weekday morning
             had to add five identical windows one at a time. --}}
        <x-ui.field label="Weekday" for="sch-weekday" name="weekday" required
                    :hint="$editingId ? null : 'A repeating answer adds one window per day it means.'">
          <select id="sch-weekday" wire:model.live="weekday" class="tb-select">
            <option value="">— select —</option>
            @if($editingId)
              @foreach($this->weekdayOptions['One day'] as $val => $lbl)
                <option value="{{ $val }}">{{ $lbl }}</option>
              @endforeach
            @else
              @foreach($this->weekdayOptions as $group => $days)
                <optgroup label="{{ $group }}">
                  @foreach($days as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                </optgroup>
              @endforeach
            @endif
          </select>
        </x-ui.field>

        {{-- A shift is a start AND an end. Asking for them as two lists asks
             the same question twice, so one click answers both. --}}
        <x-ui.suggestions lead="Usual shifts"
                          :current="$start_time.'–'.$end_time"
                          :options="collect($this->shifts)->map(fn ($t, $name) => $name)->all()"
                          :sets="$this->shifts" />

        <div class="tb-form-grid">
          <x-ui.field label="From" for="sch-start" name="start_time" required>
            <input id="sch-start" type="time" wire:model="start_time" class="tb-input" required>
            <x-ui.suggestions set="start_time" :current="$start_time"
                              :options="['07:00', '08:00', '09:00', '14:00']" />
          </x-ui.field>
          <x-ui.field label="To" for="sch-end" name="end_time" required>
            <input id="sch-end" type="time" wire:model="end_time" class="tb-input" required>
            <x-ui.suggestions set="end_time" :current="$end_time"
                              :options="['12:00', '13:00', '17:00', '20:00']" />
          </x-ui.field>
        </div>

        <x-ui.field label="Slot length (minutes)" for="sch-slot" name="slot_minutes" required
                    hint="How long one appointment takes.">
          <input id="sch-slot" type="number" wire:model="slot_minutes" class="tb-input" min="5" max="240" required>
          <x-ui.suggestions set="slot_minutes" :current="$slot_minutes"
                            :options="['10' => '10 min', '15' => '15 min', '20' => '20 min', '30' => '30 min', '45' => '45 min', '60' => '1 hour']" />
        </x-ui.field>
        <x-ui.field label="Room" name="room_id">
          <livewire:ui.select-search resource="rooms" name="room_id" :selected="$room_id"
            placeholder="Search rooms…" :key="'sch-room-'.$formNonce" />
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Add window' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
