<div>
  <h1 class="sr-only">Purchase orders</h1>

  @php($targets = 'search,status,supplier,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search PO number or supplier…" aria-label="Search purchase orders">
    </div>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">Every status</option>
      <option value="open">Waiting for goods</option>
      @foreach($statuses as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
      @endforeach
    </select>

    <select wire:model.live="supplier" class="tb-select" aria-label="Filter by supplier">
      <option value="">Every supplier</option>
      @foreach($this->suppliers as $s)
        <option value="{{ $s->id }}">{{ $s->name }}</option>
      @endforeach
    </select>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      <x-ui.link :href="route('admin.suppliers.index')" class="btn-tb">
        <i class="fas fa-truck-field" aria-hidden="true"></i> Suppliers
      </x-ui.link>
      @can('create', \App\Models\PurchaseOrder::class)
        <button type="button" wire:click="openCreate" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New purchase order</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Purchase orders, newest first</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="po_no" :sort-field="$sortField" :sort-dir="$sortDir">Order</x-ui.th-sort>
        <th>Supplier</th>
        <x-ui.th-sort field="order_date" :sort-field="$sortField" :sort-dir="$sortDir">Date</x-ui.th-sort>
        <x-ui.th-sort field="expected_date" :sort-field="$sortField" :sort-dir="$sortDir">Expected</x-ui.th-sort>
        <th class="tb-text-right">Lines</th>
        <x-ui.th-sort field="total" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Total</x-ui.th-sort>
        <x-ui.th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($rows as $r)
          <tr wire:key="po-{{ $r->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-fw-500 mono">
              <a wire:navigate href="{{ route('admin.purchase-orders.show', $r) }}">{{ $r->po_no }}</a>
            </td>
            <td>{{ $r->supplier?->name ?? '—' }}</td>
            <td class="muted tb-nowrap">{{ $r->order_date->format('j M Y') }}</td>
            <td @class(['tb-nowrap', 'muted' => ! $r->isOverdue(), 'tb-danger' => $r->isOverdue()])>
              {{ $r->expected_date?->format('j M Y') ?? '—' }}
            </td>
            <td class="tb-text-right muted">{{ $r->items_count }}</td>
            <td class="tb-text-right"><x-ui.money :amount="$r->total" /></td>
            <td><x-ui.badge :tone="$r->status->tone()">{{ $r->status->label() }}</x-ui.badge></td>
            <td class="tb-text-right tb-nowrap">
              <x-ui.actions-menu label="Actions for {{ $r->po_no }}">
                <a role="menuitem" wire:navigate href="{{ route('admin.purchase-orders.show', $r) }}">
                  <i class="fas fa-folder-open" aria-hidden="true"></i> Open
                </a>
                <a role="menuitem" target="_blank" href="{{ route('admin.purchase-orders.pdf', $r) }}">
                  <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
                </a>
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="9">
            <x-ui.empty icon="fa-file-invoice" noun="purchase orders" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  <x-ui.modal show="showCreate" size="sm" title="New purchase order">
    @if($showCreate)
      <form wire:submit="createOrder" class="tb-proc-form">
        <div class="tb-modal-body">
          <x-ui.field label="Supplier" for="po-supplier" name="supplier_id" required>
            <select id="po-supplier" wire:model="supplier_id" class="tb-select" required>
              <option value="">Choose…</option>
              @foreach($this->suppliers->where('is_active', true) as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
              @endforeach
            </select>
          </x-ui.field>
          @if($this->suppliers->where('is_active', true)->isEmpty())
            <p class="muted tb-small">No suppliers yet. <a wire:navigate href="{{ route('admin.suppliers.index') }}">Add one first.</a></p>
          @endif
          <x-ui.field label="Expected by" for="po-expected" name="expected_date">
            <input id="po-expected" type="date" wire:model="expected_date" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Notes for the supplier" for="po-notes" name="notes">
            <textarea id="po-notes" wire:model="notes" class="tb-textarea" rows="2"></textarea>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="createOrder">
            <i class="fas fa-arrow-right" aria-hidden="true"></i> Start the order
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
