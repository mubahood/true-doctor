<div wire:poll.15s.visible>
  <x-ui.page-header title="Check-in queue — today"
    :crumbs="['Appointments' => route('admin.appointments.index'), 'Queue' => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <span class="muted tb-small">Live board — refreshes every 15 seconds while this tab is open.</span>
        <span class="muted tb-small" wire:loading><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Refreshing…</span>
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <x-ui.link :href="route('admin.appointments.index')" class="btn-tb">
        <i class="fas fa-list" aria-hidden="true"></i> Diary
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  {{-- The four numbers a desk is asked for across the counter. The longest
       wait is the one that matters: a queue is judged by the person who has
       been in it longest, not by how many are in it. --}}
  @php($tally = $this->tally)
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-hourglass-half" :value="$tally['waiting']" label="Waiting"
                 :tone="$this->waitTone($tally['longest']) ?: 'ok'"
                 :sub="$tally['longest'] === null ? 'Nobody is sitting' : 'Longest '.$this->clock($tally['longest'])" />
    <x-dash.stat icon="fa-stethoscope" :value="$tally['seeing']" label="With the doctor" sub="Being seen now" />
    <x-dash.stat icon="fa-clock" :value="$tally['expected']" label="Still expected" sub="Booked and not arrived" />
    <x-dash.stat icon="fa-circle-check" tone="ok" :value="$tally['seen']" label="Seen today" sub="Written up and closed" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-filter-pick">
      <livewire:ui.select-search resource="doctors" name="doctor" :selected="$this->filterDoctor?->id"
        placeholder="Every doctor" :key="'queue-doc-'.$doctor" />
    </div>
    @if($doctor !== '')
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Every doctor</button>
    @endif
  </div>

  @php($empty = $this->rows->isEmpty())

  @if($empty)
    <div class="tb-card">
      <x-ui.empty icon="fa-mug-hot"
        :message="$doctor !== '' ? 'Nothing left for this doctor today.' : 'The queue is empty — nothing left to see today.'" />
    </div>
  @else
    @foreach($this->lanes as $key => $lane)
      @continue($lane['rows']->isEmpty())

      <div class="tb-lane" wire:key="lane-{{ $key }}">
        <div class="tb-lane-head">
          <span class="tb-lane-title">{{ $lane['title'] }}</span>
          <span class="tb-lane-n">{{ $lane['rows']->count() }}</span>
          <span class="tb-lane-note">{{ $lane['note'] }}</span>
        </div>

        <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
          <caption class="sr-only">{{ $lane['title'] }}</caption>
          <thead><tr>
            <th>{{ $key === 'expected' ? 'Due' : 'Since' }}</th>
            <th>Patient</th>
            <th>Doctor</th>
            <th>Room</th>
            <th><span class="sr-only">Actions</span></th>
          </tr></thead>
          <tbody>
            @foreach($lane['rows'] as $a)
              @php($waited = $this->waitedMinutes($a))
              @php($late = $this->lateMinutes($a))
              <tr wire:key="queue-{{ $a->id }}">
                {{-- The clock, which is the whole point of a queue. A booked
                     time alone cannot tell somebody who arrived at 08:55 and
                     has been sitting forty minutes from somebody who has just
                     walked in. --}}
                <td class="tb-nowrap">
                  @if($key === 'expected')
                    <span class="tb-q-time mono">{{ $a->scheduled_at->format('H:i') }}</span>
                    @if($late > 0)
                      <span class="tb-q-since is-bad">{{ $this->clock($late) }} late</span>
                    @else
                      <span class="tb-q-since">in {{ $this->clock(abs($late)) }}</span>
                    @endif
                  @else
                    <span class="tb-q-wait is-{{ $this->waitTone($waited) ?: 'ok' }}">
                      {{ $waited === null ? '—' : $this->clock($waited) }}
                    </span>
                    <span class="tb-q-since">booked {{ $a->scheduled_at->format('H:i') }}</span>
                  @endif
                </td>

                <td class="tb-fw-500">
                  @if($a->patient)
                    <a class="rowlink" wire:navigate href="{{ route('admin.patients.show', $a->patient) }}">{{ $a->patient->full_name }}</a>
                    <span class="tb-row-sub mono">{{ $a->patient->patient_no }}</span>
                  @else — @endif
                </td>
                <td class="muted">{{ $a->doctor?->name ?? '—' }}</td>
                <td class="muted">{{ $a->room?->name ?? '—' }}</td>

                <td class="tb-text-right tb-nowrap">
                  @can('update', $a)
                    {{-- One thing to press, named for what the desk is doing:
                         calling them in, or writing them up. Everything else
                         is in the menu, where it is not in the way. --}}
                    @php($arrived = in_array($a->status, [\App\Enums\AppointmentStatus::CheckedIn, \App\Enums\AppointmentStatus::InProgress], true))
                    @if($arrived)
                      <button type="button" class="tb-nextbtn is-record" wire:click="openOutcome({{ $a->id }})"
                              title="Write the report and charge what was provided">
                        <i class="fas fa-file-pen" aria-hidden="true"></i> Record outcome
                      </button>
                    @else
                      <button type="button" class="tb-nextbtn"
                              wire:click="advance({{ $a->id }}, '{{ \App\Enums\AppointmentStatus::CheckedIn->value }}')"
                              title="{{ $a->patient?->full_name }} has arrived">
                        <i class="fas fa-right-to-bracket" aria-hidden="true"></i> Check in
                      </button>
                    @endif
                  @endcan

                  <x-ui.actions-menu label="Actions for {{ $a->patient?->full_name ?? 'this appointment' }}">
                    <a role="menuitem" wire:navigate href="{{ route('admin.appointments.show', $a) }}">
                      <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
                    </a>
                    @can('update', $a)
                      <hr>
                      <span class="tb-menu-sec">Move to</span>
                      @foreach($a->status->transitionsTo() as $to)
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
                    @endcan
                  </x-ui.actions-menu>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table></div></div>
      </div>
    @endforeach
  @endif

  @include('livewire.appointments.partials.act-dialogs')
</div>
