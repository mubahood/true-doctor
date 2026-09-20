@php $money = app(\App\Support\HospitalSettings::class); @endphp
<x-dash.section title="Pending insurance claims" icon="fa-shield-heart" :count="$claims['count']" :href="route('admin.insurance-claims.index')">
  <div class="dash-queue">
    @forelse($rows as $c)
      <a class="dash-queue-row" wire:navigate href="{{ route('admin.insurance-claims.show', $c) }}">
        <div class="qmain"><div class="qname">{{ $c->provider?->name ?? 'Claim' }}</div>
          <div class="qmeta">{{ $c->patient?->first_name }} {{ $c->patient?->last_name }} · <x-ui.badge :tone="$c->status->badge()">{{ $c->status->label() }}</x-ui.badge></div></div>
        <span class="qtime tb-fw-600">{{ $money->format($c->amount) }}</span>
      </a>
    @empty
      <x-dash.empty icon="fa-circle-check" text="No pending claims" />
    @endforelse
  </div>
</x-dash.section>
