<div>
  <x-ui.page-header :title="$year->name"
    :crumbs="['Periods' => route('admin.financial-years.index'), $year->name => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$year->status->badge()">{{ $year->status->label() }}</x-ui.badge>
        <span class="muted tb-small">{{ $year->starts_on->format('d M Y') }} – {{ $year->ends_on->format('d M Y') }}</span>
        @if($year->closed_at)
          <span class="muted tb-small">Closed {{ $year->closed_at->format('d M Y H:i') }}@if($year->closedBy) by {{ $year->closedBy->name }}@endif</span>
        @endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <x-ui.link :href="route('admin.financial-years.index')" class="btn-tb">
        <i class="fas fa-arrow-left" aria-hidden="true"></i> All periods
      </x-ui.link>
      @can('manage', \App\Models\FinancialYear::class)
        @if($year->isOpen())
          <button type="button" class="btn-tb btn-tb-primary" wire:click="close({{ $year->id }})"
                  wire:loading.attr="disabled" wire:target="close"
                  wire:confirm="Close this period? New invoices dated within it will be blocked.">
            <span wire:loading.remove wire:target="close"><i class="fas fa-lock" aria-hidden="true"></i> Close period</span>
            <span wire:loading wire:target="close"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Closing…</span>
          </button>
        @else
          <button type="button" class="btn-tb" wire:click="reopen({{ $year->id }})"
                  wire:loading.attr="disabled" wire:target="reopen"
                  wire:confirm="Reopen this closed period? Postings into it will be allowed again.">
            <span wire:loading.remove wire:target="reopen"><i class="fas fa-lock-open" aria-hidden="true"></i> Reopen period</span>
            <span wire:loading wire:target="reopen"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Reopening…</span>
          </button>
        @endif
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="dash-root">
    <div class="tb-stats-grid">
      <x-dash.stat icon="fa-hand-holding-dollar" tone="ok"
        :value="\App\Support\HospitalSettings::money($report['payments_total'])"
        label="Payments received"
        :sub="$report['payment_count'].' payment'.($report['payment_count'] === 1 ? '' : 's')" />
      <x-dash.stat icon="fa-file-invoice-dollar"
        :value="\App\Support\HospitalSettings::money($report['invoices_total'])"
        label="Invoiced"
        :sub="$report['invoice_count'].' invoice'.($report['invoice_count'] === 1 ? '' : 's')" />
      <x-dash.stat icon="fa-scale-unbalanced" tone="bad"
        :value="\App\Support\HospitalSettings::money($report['outstanding'])"
        label="Outstanding"
        sub="Unpaid balance on invoices issued in this period" />
    </div>

    <x-dash.section title="Payments by method" icon="fa-chart-column">
      <x-dash.bars :data="$report['by_method']" :labels="$methodLabels" money />
    </x-dash.section>
  </div>

</div>
