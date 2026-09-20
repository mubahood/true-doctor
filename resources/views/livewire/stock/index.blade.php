<div>
  <h1 class="sr-only">Stock items</h1>

  @php($targets = 'search,filter,category,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters')
  @php($shelf = $this->shelf)

  {{-- What the store holds, and what is wrong with it. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-boxes-stacked" :value="$shelf['items']" label="Items stocked"
                 :sub="rtrim(rtrim(number_format((float) $shelf['quantity'], 2), '0'), '.').' units on the shelves'" />
    <x-dash.stat icon="fa-coins" :value="\App\Support\HospitalSettings::money($shelf['value'])"
                 label="What it is worth" sub="At cost" />
    <x-dash.stat icon="fa-arrow-trend-down" :tone="$shelf['low'] > 0 ? 'warn' : 'ok'"
                 :value="$shelf['low']" label="Running out"
                 :sub="$shelf['low'] > 0 ? 'At or below reorder level' : 'Nothing below its reorder level'" />
    <x-dash.stat icon="fa-hourglass-end" :tone="$shelf['expired'] > 0 ? 'bad' : ($shelf['expiring'] > 0 ? 'warn' : 'ok')"
                 :value="$shelf['expiring']" label="Expiring"
                 :sub="$shelf['expired'] > 0 ? $shelf['expired'].' already expired' : 'Within '.\App\Livewire\Stock\Alerts::EXPIRY_HORIZON_DAYS.' days'" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search item…" aria-label="Search stock items">
    </div>

    <select wire:model.live="filter" class="tb-select" aria-label="Filter stock items">
      <option value="">All items</option>
      <option value="low">Running out</option>
      <option value="expiring">Expiring soon</option>
      <option value="expired">Already expired</option>
    </select>

    @if($this->pinnedCategory)
      <button type="button" class="tb-chip is-on" wire:click="$set('category', '')">
        <i class="fas fa-layer-group" aria-hidden="true"></i> {{ $this->pinnedCategory->name }} <i class="fas fa-xmark" aria-hidden="true"></i>
      </button>
    @endif

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      <x-ui.link :href="route('admin.stock.movements')" class="btn-tb"><i class="fas fa-book" aria-hidden="true"></i> Ledger</x-ui.link>
      <x-ui.link :href="route('admin.stock.alerts')" class="btn-tb"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Alerts</x-ui.link>
      @can('create', \App\Models\StockItem::class)
        {{-- Every other catalogue could be imported from the start and the
             drugs could not, so a new hospital's pharmacy stayed empty and
             nothing could be dispensed until somebody typed one in. --}}
        <button type="button" wire:click="openSamples" class="btn-tb">
          <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples
        </button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New item</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every stock item</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">Item</x-ui.th-sort>
        <th>Category</th>
        <x-ui.th-sort field="current_quantity" :sort-field="$sortField" :sort-dir="$sortDir" align="right">On the shelf</x-ui.th-sort>
        <th class="tb-text-right">Reorder at</th>
        <x-ui.th-sort field="current_stock_value" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Value</x-ui.th-sort>
        <x-ui.th-sort field="expiry_date" :sort-field="$sortField" :sort-dir="$sortDir">Batch · expiry</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($rows as $it)
          <tr wire:key="stock-{{ $it->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-fw-500">
              {{-- Opens the record over the list. The row shows six figures;
                   the record has a dozen and the useful part — how it got to
                   this number — was a page away. --}}
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $it->id }})" title="See this item">{{ $it->name }}</button>
              @if($it->sku)<span class="tb-row-sub mono">{{ $it->sku }}</span>@endif
              @unless($it->is_active)<span class="tb-out-kind">Inactive</span>@endunless
            </td>

            <td class="muted">{{ $it->category?->name ?? '—' }}</td>

            <td class="tb-text-right mono">
              <span @class(['tb-mv-qty', 'is-in' => ! $it->isLowStock()])>
                {{ rtrim(rtrim((string) $it->current_quantity, '0'), '.') }}
              </span>
              <span class="tb-row-sub">{{ $it->unit }}</span>
            </td>

            {{-- The level the quantity beside it is being judged against: a
                 number in red means nothing without the line it crossed. --}}
            <td class="tb-text-right muted mono">{{ rtrim(rtrim((string) $it->reorder_level, '0'), '.') }}</td>

            <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($it->current_stock_value) }}</td>

            <td class="tb-nowrap muted tb-small">
              {{ $it->batch_no ?: '—' }}
              @if($it->expiry_date)
                <span @class(['tb-row-sub', 'tb-danger' => $it->isExpired()])>
                  {{ $it->isExpired() ? 'Expired ' : '' }}{{ $it->expiry_date->format('M Y') }}
                </span>
              @endif
            </td>

            <td class="tb-text-right tb-nowrap">
              @can('update', $it)
                <button type="button" class="tb-nextbtn" wire:click="openReceive({{ $it->id }})"
                        title="Put stock on this shelf"><i class="fas fa-arrow-down" aria-hidden="true"></i> Receive</button>
              @endcan

              <x-ui.actions-menu label="Actions for {{ $it->name }}">
                <button type="button" role="menuitem" wire:click="peek({{ $it->id }})">
                  <i class="fas fa-eye" aria-hidden="true"></i> Quick view
                </button>
                <a role="menuitem" wire:navigate href="{{ route('admin.stock.show', $it) }}">
                  <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record
                </a>
                <a role="menuitem" wire:navigate href="{{ route('admin.stock.movements', ['item' => $it->id]) }}">
                  <i class="fas fa-book" aria-hidden="true"></i> Everything that moved
                </a>
                @can('update', $it)
                  <hr>
                  <button type="button" role="menuitem" wire:click="openReceive({{ $it->id }})">
                    <i class="fas fa-arrow-down" aria-hidden="true"></i> Receive
                  </button>
                  <button type="button" role="menuitem" wire:click="openAdjust({{ $it->id }})">
                    <i class="fas fa-sliders" aria-hidden="true"></i> Adjust
                  </button>
                  <button type="button" role="menuitem" class="danger" wire:click="openWriteOff({{ $it->id }})">
                    <i class="fas fa-trash" aria-hidden="true"></i> Write off
                  </button>
                  <hr>
                  <button type="button" role="menuitem" wire:click="edit({{ $it->id }})">
                    <i class="fas fa-pen" aria-hidden="true"></i> Edit details
                  </button>
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">
            <x-ui.empty icon="fa-boxes-stacked" noun="stock items" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  @include('livewire.partials.stock-item-peek')

  @include('livewire.partials.stock-move-dialog')

  <x-ui.modal size="xl" show="showForm" :title="$editingId ? 'Edit stock item' : 'New stock item'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="stock-name" name="name" required>
          <input id="stock-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Category" for="stock-category" name="stock_category_id">
            <select id="stock-category" wire:model="stock_category_id" class="tb-select">
              <option value="">— none —</option>
              @foreach($this->categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Unit" for="stock-unit" name="unit" required>
            <input id="stock-unit" type="text" wire:model="unit" class="tb-input" placeholder="e.g. tablet, box" required>
          </x-ui.field>
          <x-ui.field label="SKU" for="stock-sku" name="sku">
            <input id="stock-sku" type="text" wire:model="sku" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Batch no." for="stock-batch" name="batch_no">
            <input id="stock-batch" type="text" wire:model="batch_no" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Expiry date" for="stock-expiry" name="expiry_date">
            <input id="stock-expiry" type="date" wire:model="expiry_date" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Reorder level" for="stock-reorder" name="reorder_level" required>
            <input id="stock-reorder" type="number" step="any" wire:model="reorder_level" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Cost price" for="stock-cost" name="cost_price" required>
            <input id="stock-cost" type="number" step="any" wire:model="cost_price" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Sale price" for="stock-sale" name="sale_price" required>
            <input id="stock-sale" type="number" step="any" wire:model="sale_price" class="tb-input" required>
          </x-ui.field>
        </div>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
        @if(! $editingId)<p class="muted tb-xs tb-mt-2"><i class="fas fa-circle-info" aria-hidden="true"></i> Opening quantity is added later via <strong>Receive stock</strong> on the item page.</p>@endif
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create item' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  {{-- Opening stock goes through StockService, so every quantity on the shelf
       has a ledger row behind it — even the ones that arrived with a sample
       import. --}}
  <x-ui.sample-import :samples="$samples" :open="$showSamples"
    title="Import starter pharmacy" noun="items" import-label="Import items"
    :columns="[
      ['key' => 'category', 'label' => 'Category'],
      ['key' => 'unit', 'label' => 'Unit'],
      ['key' => 'quantity', 'label' => 'Opening', 'edit' => true, 'type' => 'number'],
      ['key' => 'sale_price', 'label' => 'Sells at', 'edit' => true, 'type' => 'number'],
    ]" />
</div>
