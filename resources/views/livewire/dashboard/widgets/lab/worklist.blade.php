<x-dash.section title="Worklist" icon="fa-flask" :count="$count" :href="route('admin.lab-orders.index')">
  <div class="dash-queue">
    @forelse($rows as $o)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.lab-orders.show', $o) }}">
        <div class="qmain"><div class="qname">{{ $o->patient?->first_name }} {{ $o->patient?->last_name }}</div>
          <div class="qmeta"><x-ui.badge :tone="$o->status->badge()">{{ $o->status->label() }}</x-ui.badge></div></div>
        <span class="qtime">{{ $o->created_at->diffForHumans(null, true) }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="No orders in the worklist" />
    @endforelse
  </div>
</x-dash.section>
