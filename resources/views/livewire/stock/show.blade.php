<div>
  @php
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $expiringSoon = $item->expiry_date !== null && ! $item->isExpired()
        && $item->expiry_date->lte(now()->addDays(\App\Livewire\Stock\Alerts::EXPIRY_HORIZON_DAYS));
    $hasMovements = $this->movements->isNotEmpty();
  @endphp

  <x-ui.page-header :title="$item->name"
    :crumbs="['Stock' => route('admin.stock.index'), $item->name => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$item->is_active ? 'active' : 'danger'">{{ $item->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        @if($item->category)<span class="muted tb-small">{{ $item->category->name }}</span>@endif
        @if($item->sku)<span class="muted tb-small">SKU {{ $item->sku }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      @can('update', $item)
        <button type="button" wire:click="openReceive" class="btn-tb btn-tb-primary">
          <i class="fas fa-truck-ramp-box" aria-hidden="true"></i> Receive
        </button>
        <button type="button" wire:click="openAdjust" class="btn-tb">
          <i class="fas fa-scale-balanced" aria-hidden="true"></i> Adjust
        </button>
        {{-- The catalogue form lives in the stock register's slide-over. --}}
        <x-ui.link :href="route('admin.stock.index')" class="btn-tb">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit
        </x-ui.link>
      @endcan
      @can('delete', $item)
        @if($hasMovements)
          @if($item->is_active)
            <button type="button" class="btn-tb btn-tb-danger" wire:click="deactivate"
                    wire:loading.attr="disabled" wire:target="deactivate"
                    wire:confirm="Deactivate this item? Its ledger is kept and it stops appearing in new dispensing.">
              <i class="fas fa-ban" aria-hidden="true"></i> Deactivate
            </button>
          @endif
        @else
          <button type="button" class="btn-tb btn-tb-danger" wire:click="archive"
                  wire:loading.attr="disabled" wire:target="archive"
                  wire:confirm="Archive this item? It has no stock movements, so it can be removed from the register.">
            <i class="fas fa-box-archive" aria-hidden="true"></i> Archive
          </button>
        @endif
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Summary rail ─────────────────────────────────────────── --}}
    <div class="tb-stack">
      <div class="tb-card">
        <div class="tb-card-body tb-text-center">
          <div class="tb-label">In stock</div>
          <p class="tb-stat-value {{ $item->isLowStock() ? 'tone-bad' : 'tb-primary' }}">
            <span class="mono">{{ $qty($item->current_quantity) }}</span>
            <span class="tb-small">{{ $item->unit }}</span>
          </p>
          @if($item->isLowStock())
            <x-ui.badge tone="danger">Low stock — reorder at {{ $qty($item->reorder_level) }}</x-ui.badge>
          @endif
          @if($item->isExpired())
            <x-ui.badge tone="danger">Expired {{ $item->expiry_date->format('d M Y') }}</x-ui.badge>
          @elseif($expiringSoon)
            <x-ui.badge tone="warn">Expires {{ $item->expiry_date->format('d M Y') }}</x-ui.badge>
          @endif
        </div>

        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Item details</caption>
            <tbody>
              <tr><th scope="row">Category</th><td>{{ $item->category?->name ?? '—' }}</td></tr>
              <tr><th scope="row">Unit</th><td>{{ $item->unit }}</td></tr>
              <tr><th scope="row">Cost price</th><td><x-ui.money :amount="$item->cost_price" /></td></tr>
              <tr><th scope="row">Sale price</th><td><x-ui.money :amount="$item->sale_price" /></td></tr>
              <tr><th scope="row">Stock value</th><td><x-ui.money :amount="$item->current_stock_value" /></td></tr>
              <tr><th scope="row">Reorder level</th><td class="mono">{{ $qty($item->reorder_level) }}</td></tr>
              <tr><th scope="row">Batch</th><td>{{ $item->batch_no ?? '—' }}</td></tr>
              <tr>
                <th scope="row">Expiry</th>
                <td class="{{ $item->isExpired() ? 'tb-danger' : '' }}">{{ $item->expiry_date?->format('d M Y') ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    {{-- ── Movement ledger ──────────────────────────────────────── --}}
    <div class="tb-card">
      <div class="tb-card-header">
        <span class="tb-card-title">Movement ledger</span>
        <span class="muted tb-xs">Newest {{ \App\Livewire\Stock\Show::LEDGER_LIMIT }}</span>
      </div>
      <div class="tb-table-wrap">
        <table class="tb-table tb-small">
          <caption class="sr-only">Stock movements for {{ $item->name }}</caption>
          <thead>
            <tr>
              <th>When</th><th>Type</th>
              <th class="tb-text-right">Qty</th>
              <th class="tb-text-right">Balance after</th>
              <th class="tb-text-right">Value</th>
              <th>Note</th><th>By</th>
            </tr>
          </thead>
          <tbody>
            @forelse($this->movements as $mv)
              <tr wire:key="movement-{{ $mv->id }}">
                <td class="muted tb-nowrap">{{ $mv->created_at?->format('d M Y H:i') }}</td>
                <td><x-ui.badge :tone="$mv->reason->isIncoming() ? 'success' : 'warn'">{{ $mv->reason->label() }}</x-ui.badge></td>
                <td class="tb-text-right mono">{{ $mv->reason->isIncoming() ? '+' : '−' }}{{ $qty($mv->quantity) }}</td>
                <td class="tb-text-right mono">{{ $qty($mv->balance_after) }}</td>
                <td class="tb-text-right">
                  @if($mv->unit_cost !== null)
                    <x-ui.money :amount="bcmul((string) $mv->quantity, (string) $mv->unit_cost, 2)" />
                  @else
                    <span class="muted">—</span>
                  @endif
                </td>
                <td class="muted">{{ $mv->note ?? '—' }}</td>
                <td class="muted">{{ $mv->createdBy?->name ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="7"><x-ui.empty icon="fa-right-left" noun="stock movements" /></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- ── Slide-over: receive (pessimistic) ──────────────────────── --}}
  <x-ui.modal size="md" show="showReceive" title="Receive stock">
    @if($showReceive)
      <form wire:submit="receive" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <div class="tb-flex">
              <span class="muted tb-small">{{ $item->name }} · in stock</span>
              <span class="mono tb-fw-600">{{ $qty($item->current_quantity) }} {{ $item->unit }}</span>
            </div>
          </div>

          <div class="tb-form-grid">
            <x-ui.field label="Quantity" for="rc-qty" name="quantity" required>
              <input id="rc-qty" type="number" step="any" min="0.01" wire:model="quantity" class="tb-input" required>
            </x-ui.field>
            <x-ui.field label="Unit cost" for="rc-cost" name="unit_cost" hint="Leave blank to keep the current cost price.">
              <input id="rc-cost" type="number" step="0.01" min="0" wire:model="unit_cost" class="tb-input">
            </x-ui.field>
          </div>

          <x-ui.field label="Note / PO reference" for="rc-note" name="note">
            <input id="rc-note" type="text" wire:model="note" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="receive">
            <span wire:loading.remove wire:target="receive"><i class="fas fa-check" aria-hidden="true"></i> Receive</span>
            <span wire:loading wire:target="receive"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Slide-over: adjust (pessimistic) ───────────────────────── --}}
  <x-ui.modal size="md" show="showAdjust" title="Adjust stock">
    @if($showAdjust)
      <form wire:submit="adjust" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <div class="tb-flex">
              <span class="muted tb-small">{{ $item->name }} · in stock</span>
              <span class="mono tb-fw-600">{{ $qty($item->current_quantity) }} {{ $item->unit }}</span>
            </div>
          </div>

          <div class="tb-form-grid">
            <x-ui.field label="Reason" for="aj-reason" name="reason" required>
              <select id="aj-reason" wire:model.live="reason" class="tb-select">
                @foreach($reasons as $r)
                  <option value="{{ $r->value }}">{{ $r->label() }}</option>
                @endforeach
              </select>
            </x-ui.field>
            @php($picked = \App\Enums\StockMovementReason::tryFrom($reason))
            <x-ui.field label="Quantity" for="aj-qty" name="quantity" required
              :hint="$picked?->isIncoming() ? 'Added to stock.' : 'Removed from stock.'">
              <input id="aj-qty" type="number" step="any" min="0.01" wire:model="quantity" class="tb-input" required>
            </x-ui.field>
          </div>

          <x-ui.field label="Note" for="aj-note" name="note">
            <input id="aj-note" type="text" wire:model="note" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="adjust">
            <span wire:loading.remove wire:target="adjust"><i class="fas fa-check" aria-hidden="true"></i> Record adjustment</span>
            <span wire:loading wire:target="adjust"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
