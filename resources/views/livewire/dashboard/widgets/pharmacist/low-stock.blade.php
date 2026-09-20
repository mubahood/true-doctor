@php $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); @endphp
<x-dash.section title="Low stock" icon="fa-triangle-exclamation" :count="$count"
  :href="route('admin.stock.alerts')" viewLabel="Alerts">
  <div class="dash-queue">
    @forelse($rows as $item)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.stock.show', $item) }}">
        <div class="qmain"><div class="qname">{{ $item->name }}</div>
          <div class="qmeta">Reorder at {{ $num($item->reorder_level) }} {{ $item->unit }}</div></div>
        <span class="qtime tb-danger tb-fw-600">{{ $num($item->current_quantity) }} left</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="All items above reorder level" />
    @endforelse
  </div>
</x-dash.section>
