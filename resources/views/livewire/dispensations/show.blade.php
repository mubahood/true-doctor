<div>
  @php
    $total = $dispensation->items->reduce(fn ($carry, $item) => bcadd($carry, (string) $item->line_total, 2), '0.00');
    $crumbs = ['Visits' => route('admin.visits.index')];
    if ($dispensation->visit) {
        $crumbs[$dispensation->visit->visit_no] = route('admin.visits.show', $dispensation->visit);
    }
    $crumbs[$dispensation->created_at->format('d M Y H:i')] = null;
  @endphp

  <x-ui.page-header title="Dispensation" :crumbs="$crumbs">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        @if($dispensation->patient)
          <x-ui.link :href="route('admin.patients.show', $dispensation->patient)" class="tb-small">{{ $dispensation->patient->full_name }}</x-ui.link>
        @endif
        @if($dispensation->dispensedBy)<span class="muted tb-small">Dispensed by {{ $dispensation->dispensedBy->name }}</span>@endif
      </div>
    </x-slot:subtitle>
  </x-ui.page-header>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Items</span></div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th>Drug</th><th class="tb-text-right">Qty</th><th class="tb-text-right">Unit price</th><th class="tb-text-right">Line total</th></tr></thead>
      <tbody>
        @forelse($dispensation->items as $it)
          <tr wire:key="disp-item-{{ $it->id }}">
            <td class="tb-fw-500">{{ $it->name }}</td>
            <td class="tb-text-right mono">{{ rtrim(rtrim((string) $it->quantity, '0'), '.') }}</td>
            <td class="tb-text-right"><x-ui.money :amount="$it->unit_price" /></td>
            <td class="tb-text-right"><x-ui.money :amount="$it->line_total" /></td>
          </tr>
        @empty
          <tr><td colspan="4"><x-ui.empty icon="fa-pills" noun="dispensed items" /></td></tr>
        @endforelse
      </tbody>
      <tfoot>
        <tr><th colspan="3" class="tb-text-right">Total</th><th class="tb-text-right"><x-ui.money :amount="$total" /></th></tr>
      </tfoot>
    </table></div>
    @if($dispensation->note)
      <div class="tb-card-body muted">{{ $dispensation->note }}</div>
    @endif
  </div>
</div>
