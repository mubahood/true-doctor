<div>
  <x-ui.page-header title="Lab order — {{ $order->patient?->full_name ?? 'Unknown patient' }}"
    :crumbs="['Lab orders' => route('admin.lab-orders.index'), $order->created_at->format('d M Y') => null]">
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
      <x-ui.link :href="route('admin.lab-orders.pdf', $order)" :navigate="false"
                 target="_blank" rel="noopener" class="btn-tb">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  @can('lab.process')
    @if($order->status->transitionsTo())
      <div class="tb-card tb-mb-5"><div class="tb-card-body tb-flex">
        <span class="tb-label">Advance</span>
        @foreach($order->status->transitionsTo() as $next)
          <button type="button" wire:key="lab-transition-{{ $next->value }}"
            wire:click="transition('{{ $next->value }}')"
            wire:loading.attr="disabled" wire:target="transition('{{ $next->value }}')"
            @if($next === \App\Enums\LabOrderStatus::Cancelled) wire:confirm="Cancel this lab order? This cannot be undone." @endif
            class="btn-tb btn-tb-sm {{ $next === \App\Enums\LabOrderStatus::Cancelled ? 'btn-tb-danger' : 'btn-tb-primary' }}">
            <span wire:loading.remove wire:target="transition('{{ $next->value }}')">{{ $next->label() }}</span>
            <span wire:loading wire:target="transition('{{ $next->value }}')"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        @endforeach
      </div></div>
    @endif
  @endcan

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Tests &amp; results</span></div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr>
        <th>Test</th><th>Reference range</th><th>Unit</th><th>Result</th><th>Flag</th><th>Notes</th>
      </tr></thead>
      <tbody>
        @forelse($order->items as $it)
          <tr wire:key="lab-item-{{ $it->id }}">
            <td class="tb-fw-500">{{ $it->name }}</td>
            <td class="muted">{{ $it->reference_range ?? '—' }}</td>
            <td class="muted">{{ $it->unit ?? '—' }}</td>
            @can('lab.process')
              <td>
                <label class="sr-only" for="result-value-{{ $it->id }}">Result value for {{ $it->name }}</label>
                <input id="result-value-{{ $it->id }}" type="text" class="tb-input"
                  wire:model.blur="results.{{ $it->id }}.result_value" placeholder="Value">
                @error("results.{$it->id}.result_value")<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
              </td>
              <td>
                <label class="sr-only" for="result-flag-{{ $it->id }}">Flag for {{ $it->name }}</label>
                <select id="result-flag-{{ $it->id }}" class="tb-select" wire:model.blur="results.{{ $it->id }}.result_flag">
                  <option value="">— none —</option>
                  @foreach(\App\Enums\ResultFlag::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                </select>
                @error("results.{$it->id}.result_flag")<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
              </td>
              <td>
                <label class="sr-only" for="result-notes-{{ $it->id }}">Notes for {{ $it->name }}</label>
                <input id="result-notes-{{ $it->id }}" type="text" class="tb-input"
                  wire:model.blur="results.{{ $it->id }}.result_notes" placeholder="Notes">
                <span class="muted tb-xs" wire:loading wire:target="results.{{ $it->id }}"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
                @error("results.{$it->id}.result_notes")<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
              </td>
            @else
              <td class="mono">{{ $it->result_value ?? '—' }}</td>
              <td>@if($it->result_flag)<x-ui.badge :tone="$it->result_flag->badge()">{{ $it->result_flag->label() }}</x-ui.badge>@else<span class="muted">—</span>@endif</td>
              <td class="muted">{{ $it->result_notes ?? '—' }}</td>
            @endcan
          </tr>
        @empty
          <tr><td colspan="6"><x-ui.empty icon="fa-flask" noun="tests" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
    @if($order->clinical_notes)
      <div class="tb-card-body muted">Clinical notes: {{ $order->clinical_notes }}</div>
    @endif
  </div>
</div>
