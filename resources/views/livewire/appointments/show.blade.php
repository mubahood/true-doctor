<div>
  @php($destructive = [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow])

  <x-ui.page-header :title="$appointment->patient?->full_name ?? 'Appointment'"
    :crumbs="['Appointments' => route('admin.appointments.index'), $appointment->scheduled_at->format('d M Y H:i') => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$appointment->status->badge()">{{ $appointment->status->label() }}</x-ui.badge>
        @if($appointment->patient)
          <x-ui.link :href="route('admin.patients.show', $appointment->patient)" class="tb-small">{{ $appointment->patient->full_name }}</x-ui.link>
        @endif
        <span class="muted tb-small">{{ $appointment->scheduled_at->format('d M Y H:i') }}–{{ $appointment->ends_at->format('H:i') }} · {{ $appointment->duration_minutes }} min</span>
        @if($appointment->doctor)<span class="muted tb-small">{{ $appointment->doctor->name }}</span>@endif
        @if($appointment->room)<span class="muted tb-small">{{ $appointment->room->name }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      @can('update', $appointment)
        @unless($appointment->status->isTerminal())
          <button type="button" class="btn-tb" wire:click="openReschedule" wire:loading.attr="disabled" wire:target="openReschedule">
            <i class="fas fa-calendar-day" aria-hidden="true"></i> Reschedule
          </button>
        @endunless
        @if($appointment->status === \App\Enums\AppointmentStatus::CheckedIn)
          <button type="button" class="btn-tb btn-tb-primary" wire:click="openVisit"
                  wire:loading.attr="disabled" wire:target="openVisit">
            <span wire:loading.remove wire:target="openVisit"><i class="fas fa-stethoscope" aria-hidden="true"></i> Open visit</span>
            <span wire:loading wire:target="openVisit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Opening…</span>
          </button>
        @endif
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Summary ──────────────────────────────────────────────── --}}
    <div class="tb-card">
      <div class="tb-card-body tb-text-center">
        <x-ui.badge :tone="$appointment->status->badge()">{{ $appointment->status->label() }}</x-ui.badge>
        <div class="tb-fw-500 tb-mt-2">{{ $appointment->scheduled_at->format('d M Y') }}</div>
        <div class="mono tb-primary">{{ $appointment->scheduled_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}</div>
      </div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Appointment details</caption>
        <tbody>
          <tr><th scope="row">Patient</th><td>
            @if($appointment->patient)
              <x-ui.link :href="route('admin.patients.show', $appointment->patient)">{{ $appointment->patient->full_name }}</x-ui.link>
            @else — @endif
          </td></tr>
          <tr><th scope="row">Patient no.</th><td class="mono">{{ $appointment->patient?->patient_no ?? '—' }}</td></tr>
          <tr><th scope="row">Doctor</th><td>{{ $appointment->doctor?->name ?? '—' }}</td></tr>
          <tr><th scope="row">Department</th><td>{{ $appointment->department?->name ?? '—' }}</td></tr>
          <tr><th scope="row">Room</th><td>{{ $appointment->room?->name ?? '—' }}</td></tr>
          <tr><th scope="row">Duration</th><td>{{ $appointment->duration_minutes }} min</td></tr>
          <tr><th scope="row">Source</th><td>{{ $appointment->source->label() }}</td></tr>
          <tr><th scope="row">Reason</th><td>{{ $appointment->reason ?? '—' }}</td></tr>
          @if($appointment->cancel_reason)
            <tr><th scope="row">Cancel reason</th><td>{{ $appointment->cancel_reason }}</td></tr>
          @endif
        </tbody>
      </table></div>
    </div>

    <div class="tb-stack">
      {{-- ── Transitions ────────────────────────────────────────── --}}
      @can('update', $appointment)
        @if($appointment->status->transitionsTo())
          <div class="tb-card">
            <div class="tb-card-header"><span class="tb-card-title">Advance</span></div>
            <div class="tb-card-body">
              <div class="tb-flex">
                @foreach($appointment->status->transitionsTo() as $next)
                  @php($isDestructive = in_array($next, $destructive, true))
                  @if($next === \App\Enums\AppointmentStatus::Completed)
                    {{-- Completing is not a status to press. It is recording
                         what was done, which happens on the diary where the
                         report and the services are asked for — and the
                         service refuses an empty completion anyway. --}}
                    <a class="btn-tb btn-tb-sm btn-tb-primary" wire:navigate
                       href="{{ route('admin.appointments.index') }}?q={{ urlencode($appointment->patient?->last_name ?? '') }}">
                      <i class="fas fa-file-pen" aria-hidden="true"></i> Record outcome
                    </a>
                  @else
                    <button type="button" wire:key="appt-transition-{{ $next->value }}"
                      wire:click="transition('{{ $next->value }}')"
                      wire:loading.attr="disabled" wire:target="transition('{{ $next->value }}')"
                      @if($isDestructive) wire:confirm="Move this appointment to {{ $next->label() }}? This cannot be undone." @endif
                      class="btn-tb btn-tb-sm {{ $isDestructive ? 'btn-tb-danger' : 'btn-tb-primary' }}">
                      <span wire:loading.remove wire:target="transition('{{ $next->value }}')">{{ $next->label() }}</span>
                      <span wire:loading wire:target="transition('{{ $next->value }}')"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
                    </button>
                  @endif
                @endforeach
              </div>

              @if(array_filter($appointment->status->transitionsTo(), fn ($s) => in_array($s, $destructive, true)))
                <x-ui.field label="Reason" for="appt-note" name="note" class="tb-mt-3"
                  hint="Recorded on the history entry when you cancel or mark a no-show.">
                  <input id="appt-note" type="text" class="tb-input" wire:model="note" maxlength="255">
                </x-ui.field>
              @endif
            </div>
          </div>
        @endif
      @endcan

      {{-- ── Status history ─────────────────────────────────────── --}}
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">History</span></div>
        <div class="tb-card-body">
          <div class="tb-timeline">
            @forelse($appointment->history as $h)
              <div class="tb-tl-item" wire:key="appt-history-{{ $h->id }}">
                <div class="t">{{ $h->from_status?->label() ?? 'Booked' }} → {{ $h->to_status->label() }}</div>
                <div class="m">{{ $h->created_at?->format('d M Y H:i') }}@if($h->changedBy) · {{ $h->changedBy->name }}@endif</div>
                @if($h->note)<div class="m">{{ $h->note }}</div>@endif
              </div>
            @empty
              <x-ui.empty icon="fa-clock-rotate-left" noun="history entries" />
            @endforelse
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- ── Slide-over: reschedule ──────────────────────────────────── --}}
  <x-ui.modal size="md" show="showReschedule" title="Reschedule appointment">
    @if($showReschedule)
      <form wire:submit="reschedule" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <span class="muted tb-small">Currently {{ $appointment->scheduled_at->format('d M Y H:i') }} with {{ $appointment->doctor?->name ?? 'no doctor' }}.</span>
          </div>

          <x-ui.field label="New date &amp; time" for="rs-at" name="scheduled_at" required>
            <input id="rs-at" type="datetime-local" class="tb-input" wire:model="scheduled_at" required>
          </x-ui.field>

          <x-ui.field label="Duration (minutes)" for="rs-duration" name="duration_minutes" required>
            <input id="rs-duration" type="number" min="5" max="480" step="5" class="tb-input" wire:model="duration_minutes" required>
            <x-ui.suggestions set="duration_minutes" :current="$duration_minutes"
                              :options="['15' => '15 min', '30' => '30 min', '45' => '45 min', '60' => '1 hour']" />
          </x-ui.field>

          <x-ui.field label="Room" name="room_id" hint="Leave empty to free the room.">
            <livewire:ui.select-search resource="rooms" name="room_id" :selected="$room_id"
              placeholder="Search rooms…" :key="'appt-room-'.$formNonce" />
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="reschedule">
            <span wire:loading.remove wire:target="reschedule"><i class="fas fa-check" aria-hidden="true"></i> Reschedule</span>
            <span wire:loading wire:target="reschedule"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
