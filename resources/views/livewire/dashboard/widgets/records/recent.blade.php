<x-dash.section title="Recently registered" icon="fa-clock-rotate-left" :href="route('admin.patients.index')">
  <div class="dash-queue">
    @forelse($rows as $p)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.patients.show', $p) }}">
        <div class="qmain"><div class="qname">{{ $p->first_name }} {{ $p->last_name }}</div>
          <div class="qmeta">{{ $p->patient_no }}</div></div>
        <span class="qtime">{{ $p->created_at->diffForHumans(null, true) }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-user" text="No patients registered yet" />
    @endforelse
  </div>
</x-dash.section>
