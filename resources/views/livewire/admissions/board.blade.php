<div wire:poll.30s.visible>
  <x-ui.page-header title="Occupancy board"
    :crumbs="['Dashboard' => route('admin.dashboard'), 'Occupancy' => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <span class="muted tb-small">Live board — refreshes every 30 seconds while this tab is open.</span>
        <span class="muted tb-small" wire:loading><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Refreshing…</span>
      </div>
    </x-slot:subtitle>
    <x-slot:explain>
      Every bed in every active ward. A tile opens the bed over the board — who is
      in it, how long they have been there, what the stay has cost so far — and the
      things you can do to it from there: admit, transfer, discharge, or take the
      bed out of service. The figures at the top count the whole hospital, not
      whatever the filters have narrowed the board to.
    </x-slot:explain>
    <x-slot:actions>
      <x-ui.link :href="route('admin.admissions.index')" class="btn-tb">
        <i class="fas fa-list" aria-hidden="true"></i> Admissions
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  {{-- What the hospital stands at. Deliberately not filtered: "seven beds
       free" has to mean seven beds free. --}}
  @php($fig = $this->figures)
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-bed" :value="$fig['total']" label="Beds" />
    <x-dash.stat icon="fa-bed-pulse" tone="warn" :value="$fig['occupied']" label="Occupied" />
    <x-dash.stat icon="fa-check" tone="ok" :value="$fig['free']" label="Free now" />
    <x-dash.stat icon="fa-screwdriver-wrench" :tone="$fig['maintenance'] > 0 ? 'bad' : ''"
                 :value="$fig['maintenance']" label="Out of service" />
  </div>

  <div class="tb-card tb-mb-4"><div class="tb-card-body">
    <div class="tb-occ">
      <span class="tb-occ-n">{{ $fig['percent'] }}% full</span>
      <span class="tb-occ-bar">
        <span @class(['tb-occ-fill', 'is-tight' => $fig['percent'] >= 80, 'is-full' => $fig['percent'] >= 95])
              style="width:{{ $fig['percent'] }}%"></span>
      </span>
      <span class="tb-occ-n">
        @if($fig['free'] === 0)
          No bed free
        @else
          {{ $fig['free'] }} {{ Str::plural('bed', $fig['free']) }} free
        @endif
      </span>
    </div>
  </div></div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap" style="position:relative;">
      <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:12px;" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Bed name or the patient in it…" aria-label="Search beds and patients"
             style="padding-left:32px;min-width:240px;">
    </div>

    <select wire:model.live="ward" class="tb-select" aria-label="Filter by ward">
      <option value="">Every ward</option>
      @foreach($this->wardOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
    </select>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by bed status">
      <option value="">Any bed</option>
      @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <div wire:loading.flex wire:target="search,ward,status" class="muted tb-small tb-flex" style="gap:6px;align-items:center;">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…
    </div>
  </div>

  @forelse($this->wards as $ward)
    @php($taken = $ward->beds->where('status', $occupied)->count())
    <div class="tb-card tb-mb-4" wire:key="ward-{{ $ward->id }}">
      <div class="tb-card-header">
        <span class="tb-card-title">
          {{ $ward->name }}
          <span class="muted tb-small">· {{ $taken }}/{{ $ward->beds->count() }} occupied</span>
        </span>
      </div>
      <div class="tb-card-body">
        <div class="tb-bedgrid">
          @forelse($ward->beds as $bed)
            @php($stay = $bed->currentAdmission)
            <button type="button" wire:key="bed-{{ $bed->id }}" wire:click="peek({{ $bed->id }})"
                    @class([
                      'tb-bed',
                      'is-taken' => $stay !== null,
                      'is-free' => $stay === null && $bed->status === \App\Enums\BedStatus::Available,
                      'is-down' => $stay === null && $bed->status === \App\Enums\BedStatus::Maintenance,
                    ])
                    title="See {{ $bed->name }}">
              <span class="tb-bed-icon">
                <i @class(['fas', 'fa-bed-pulse' => $stay !== null, 'fa-bed' => $stay === null]) aria-hidden="true"></i>
              </span>
              <span class="tb-bed-body">
                <span class="tb-bed-name">{{ $bed->name }}</span>
                @if($stay)
                  <span class="tb-bed-who">{{ $stay->patient?->full_name ?? 'Occupied' }}</span>
                  <span class="tb-bed-sub">
                    {{ $stay->nights() }} {{ Str::plural('night', $stay->nights()) }} · since {{ $stay->admitted_at->format('j M') }}
                  </span>
                @else
                  <span class="tb-bed-who muted">{{ $bed->status->label() }}</span>
                  <span class="tb-bed-sub">{{ \App\Support\HospitalSettings::money($bed->daily_charge) }}/night</span>
                @endif
              </span>
            </button>
          @empty
            <span class="muted tb-small">No beds match in this ward.</span>
          @endforelse
        </div>
      </div>
    </div>
  @empty
    <div class="tb-card"><div class="tb-card-body">
      <x-ui.empty icon="fa-hospital"
        :message="$search !== '' || $ward !== '' || $status !== ''
          ? 'No bed matches what you are looking for.'
          : 'No active wards. Create wards and beds first.'" />
    </div></div>
  @endforelse

  @include('livewire.admissions.partials.bed-peek')
  @include('livewire.admissions.partials.bed-actions')
</div>
