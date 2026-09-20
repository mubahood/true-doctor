<x-dash.section title="Waiting for vitals" icon="fa-heart-pulse" :count="$rows->count()" :href="route('admin.visits.index')">
  <div class="dash-queue">
    @forelse($rows as $c)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.visits.show', $c) }}">
        <div class="qmain"><div class="qname">{{ $c->patient?->first_name }} {{ $c->patient?->last_name }}</div>
          <div class="qmeta">{{ $c->visit_no }}</div></div>
        <span class="qtime">{{ $c->created_at->diffForHumans(null, true) }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="No one waiting for vitals" />
    @endforelse
  </div>
</x-dash.section>
