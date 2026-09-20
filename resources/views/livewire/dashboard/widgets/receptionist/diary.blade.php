<x-dash.section title="Today's appointments" icon="fa-calendar-day" :count="$rows->count()" :href="route('admin.appointments.queue')">
  <div class="dash-queue">
    @forelse($rows as $a)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.appointments.show', $a) }}">
        <div class="qmain"><div class="qname">{{ $a->patient?->first_name }} {{ $a->patient?->last_name }}</div>
          <div class="qmeta">{{ $a->doctor?->name }} · <x-ui.badge :tone="$a->status->badge()">{{ $a->status->label() }}</x-ui.badge></div></div>
        <span class="qtime">{{ $a->scheduled_at->format('H:i') }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-calendar" text="No appointments today" />
    @endforelse
  </div>
</x-dash.section>
