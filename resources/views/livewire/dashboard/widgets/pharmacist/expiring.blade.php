@php $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); @endphp
<x-dash.section title="Expiring soon" icon="fa-hourglass-half" :count="$count" :href="route('admin.stock.index')">
  <div class="dash-queue">
    @forelse($rows as $item)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.stock.show', $item) }}">
        <div class="qmain"><div class="qname">{{ $item->name }}</div>
          <div class="qmeta">{{ $num($item->current_quantity) }} {{ $item->unit }} in stock</div></div>
        <span class="qtime tb-warn">{{ $item->expiry_date?->format('d M Y') }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="Nothing expiring within 90 days" />
    @endforelse
  </div>
</x-dash.section>
