@php $money = app(\App\Support\HospitalSettings::class); @endphp
<x-dash.section title="Awaiting payment" icon="fa-file-invoice-dollar" :count="$count" :href="route('admin.invoices.index')">
  <div class="dash-queue">
    @forelse($rows as $inv)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.invoices.show', $inv) }}">
        <div class="qmain"><div class="qname">{{ $inv->patient?->first_name }} {{ $inv->patient?->last_name }}</div>
          <div class="qmeta">{{ $inv->invoice_no }} · <x-ui.badge :tone="$inv->status->badge()">{{ $inv->status->label() }}</x-ui.badge></div></div>
        <span class="qtime tb-warn tb-fw-600">{{ $money->format($inv->balance) }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="Nothing awaiting payment" />
    @endforelse
  </div>
</x-dash.section>
