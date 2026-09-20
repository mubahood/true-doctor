<div>
  <x-ui.page-header title="Radiology — {{ $order->patient?->full_name ?? 'Unknown patient' }}"
    :crumbs="['Radiology orders' => route('admin.radiology-orders.index'), $order->created_at->format('d M Y') => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge>
        @if($order->patient)
          <x-ui.link :href="route('admin.patients.show', $order->patient)" class="tb-small">{{ $order->patient->full_name }}</x-ui.link>
        @endif
        @if($order->orderedBy)<span class="muted tb-small">Ordered by {{ $order->orderedBy->name }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      {{-- Leaves the SPA on purpose: a generated document opens in its own
           tab rather than replacing the page (docs/documents.md). --}}
      <x-ui.link :href="route('admin.radiology-orders.pdf', $order)" :navigate="false"
                 target="_blank" rel="noopener" class="btn-tb">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  @can('radiology.report')
    @if($order->status->transitionsTo())
      <div class="tb-card tb-mb-5"><div class="tb-card-body tb-flex">
        <span class="tb-label">Advance</span>
        @foreach($order->status->transitionsTo() as $next)
          <button type="button" wire:key="rad-transition-{{ $next->value }}"
            wire:click="transition('{{ $next->value }}')"
            wire:loading.attr="disabled" wire:target="transition('{{ $next->value }}')"
            @if($next === \App\Enums\RadiologyOrderStatus::Cancelled) wire:confirm="Cancel this radiology order? This cannot be undone." @endif
            class="btn-tb btn-tb-sm {{ $next === \App\Enums\RadiologyOrderStatus::Cancelled ? 'btn-tb-danger' : 'btn-tb-primary' }}">
            <span wire:loading.remove wire:target="transition('{{ $next->value }}')">{{ $next->label() }}</span>
            <span wire:loading wire:target="transition('{{ $next->value }}')"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        @endforeach
      </div></div>
    @endif
  @endcan

  <div class="tb-card tb-mb-5">
    <div class="tb-card-header"><span class="tb-card-title">Studies</span></div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th>Study</th><th>Modality</th><th class="tb-text-right">Price</th></tr></thead>
      <tbody>
        @forelse($order->items as $it)
          <tr wire:key="rad-item-{{ $it->id }}">
            <td class="tb-fw-500">{{ $it->name }}</td>
            <td class="muted">{{ $it->modality ?? '—' }}</td>
            <td class="tb-text-right"><x-ui.money :amount="$it->price" /></td>
          </tr>
        @empty
          <tr><td colspan="3"><x-ui.empty icon="fa-x-ray" noun="studies" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Report</span></div>
    <div class="tb-card-body">
      @can('radiology.report')
        <form wire:submit="saveReport">
          <x-ui.field label="Findings" for="rad-findings" name="findings">
            <textarea id="rad-findings" wire:model="findings" class="tb-textarea" rows="5"></textarea>
          </x-ui.field>
          <x-ui.field label="Impression" for="rad-impression" name="impression">
            <textarea id="rad-impression" wire:model="impression" class="tb-textarea" rows="3"></textarea>
          </x-ui.field>
          <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary tb-mt-3" wire:loading.attr="disabled" wire:target="saveReport">
            <span wire:loading.remove wire:target="saveReport"><i class="fas fa-check" aria-hidden="true"></i> Save report</span>
            <span wire:loading wire:target="saveReport"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </form>
      @else
        <div class="tb-label">Findings</div>
        <p>{{ $order->findings ?? '—' }}</p>
        <div class="tb-label tb-mt-3">Impression</div>
        <p>{{ $order->impression ?? '—' }}</p>
      @endcan
      @if($order->clinical_notes)
        <div class="muted tb-mt-4">Clinical notes: {{ $order->clinical_notes }}</div>
      @endif
      @if($order->reported_at)
        <div class="muted tb-xs tb-mt-2">Reported {{ $order->reported_at->format('d M Y H:i') }}</div>
      @endif
    </div>
  </div>
</div>
