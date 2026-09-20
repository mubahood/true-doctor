<div>
  <h1 class="sr-only">Stock categories</h1>

  @php($targets = 'search,status,attention,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters')
  @php($store = $this->store)

  {{-- What is on the shelves, across all of them. A storekeeper opens this
       page to find out what is running out; that was the one thing it could
       not say. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-layer-group" :value="$store['categories']" label="Categories"
                 :sub="$store['items'].' '.\Illuminate\Support\Str::plural('item', $store['items']).' in them'" />
    <x-dash.stat icon="fa-boxes-stacked" :value="rtrim(rtrim(number_format((float) $store['quantity'], 2), '0'), '.') ?: '0'"
                 label="Units in store" sub="Across every active item" />
    <x-dash.stat icon="fa-triangle-exclamation" :tone="$store['low'] > 0 ? 'warn' : 'ok'"
                 :value="$store['low']" label="Running out"
                 :sub="$store['low'] > 0 ? 'At or below reorder level' : 'Nothing below its reorder level'"
                 :href="$store['low'] > 0 ? route('admin.stock.index', ['filter' => 'low']) : null" />
    <x-dash.stat icon="fa-hourglass-end" :tone="$store['expiring'] > 0 ? 'warn' : 'ok'"
                 :value="$store['expiring']" label="Expiring"
                 :sub="'Within '.\App\Livewire\StockCategories\Index::EXPIRY_HORIZON_DAYS.' days'"
                 :href="$store['expiring'] > 0 ? route('admin.stock.index', ['filter' => 'expiring']) : null" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search category…" aria-label="Search stock categories">
    </div>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">Active and inactive</option>
      <option value="active">Active only</option>
      <option value="inactive">Inactive only</option>
    </select>

    <label class="tb-check-group" title="Categories with something running out or expiring">
      <input type="checkbox" wire:model.live="attention"> Needs attention
    </label>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\StockCategory::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New category</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every stock category and what is on its shelf</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">Category</x-ui.th-sort>
        <th>Unit</th>
        <x-ui.th-sort field="items_count" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Items</x-ui.th-sort>
        <x-ui.th-sort field="on_hand" :sort-field="$sortField" :sort-dir="$sortDir" align="right">In store</x-ui.th-sort>
        <x-ui.th-sort field="low_count" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Running out</x-ui.th-sort>
        <th class="tb-text-right">Expiring</th>
        <x-ui.th-sort field="stock_value" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Value</x-ui.th-sort>
        <x-ui.th-sort field="is_active" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($rows as $r)
          <tr wire:key="stock-categories-{{ $r->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-fw-500">
              {{-- Opens the category over the list: what is ON the shelf is the
                   question a count is standing in for, and the shelf itself is
                   one click further, in the dialog. --}}
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                      title="See this category">{{ $r->name }}</button>
              @if($r->description)<span class="tb-row-sub">{{ \Illuminate\Support\Str::limit($r->description, 60) }}</span>@endif
            </td>

            <td class="muted">{{ $r->unit }}</td>

            <td class="tb-text-right muted">
              {{ $r->items_count }}
              @if($r->items_count > $r->active_count)
                <span class="tb-row-sub">{{ $r->items_count - $r->active_count }} inactive</span>
              @endif
            </td>

            {{-- How much is actually there, which is what "stock" means. --}}
            <td class="tb-text-right mono">
              @if($r->active_count > 0)
                {{ rtrim(rtrim(number_format((float) ($r->on_hand ?? 0), 2), '0'), '.') ?: '0' }}
              @else
                <span class="muted">—</span>
              @endif
            </td>

            {{-- The question this page exists to answer. --}}
            <td class="tb-text-right">
              @if($r->low_count > 0)
                <a class="tb-warnpill" wire:navigate
                   href="{{ route('admin.stock.index', ['category' => $r->id, 'filter' => 'low']) }}"
                   title="{{ $r->low_count }} at or below reorder level">{{ $r->low_count }}</a>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            <td class="tb-text-right">
              @if($r->expiring_count > 0)
                <a class="tb-warnpill" wire:navigate
                   href="{{ route('admin.stock.index', ['category' => $r->id, 'filter' => 'expiring']) }}"
                   title="{{ $r->expiring_count }} expiring within {{ \App\Livewire\StockCategories\Index::EXPIRY_HORIZON_DAYS }} days">{{ $r->expiring_count }}</a>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            <td class="tb-text-right mono">
              @if($r->active_count > 0)
                {{ \App\Support\HospitalSettings::money($r->stock_value ?? 0) }}
              @else
                <span class="muted">—</span>
              @endif
            </td>

            <td><x-ui.badge :tone="$r->is_active ? 'success' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>

            <td class="tb-text-right tb-nowrap">
              <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
              <x-ui.actions-menu label="Actions for {{ $r->name }}">
                <a role="menuitem" wire:navigate href="{{ route('admin.stock.index', ['category' => $r->id]) }}">
                  <i class="fas fa-boxes-stacked" aria-hidden="true"></i> What is on this shelf
                </a>
                @if($r->low_count > 0)
                  <a role="menuitem" wire:navigate href="{{ route('admin.stock.index', ['category' => $r->id, 'filter' => 'low']) }}">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> What is running out
                  </a>
                @endif
                @can('update', $r)
                  <hr>
                  <button type="button" role="menuitem" wire:click="edit({{ $r->id }})"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button>
                @endcan
                @can('delete', $r)
                  <button type="button" role="menuitem" class="danger" wire:click="delete({{ $r->id }})"
                          wire:confirm="Delete this category?"><i class="fas fa-trash" aria-hidden="true"></i> Delete</button>
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="10">
            <x-ui.empty icon="fa-layer-group" noun="categories" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit category' : 'New category'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="cat-name" name="name" required>
          <input id="cat-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>

        {{-- What is already on this shelf, while it is being edited: renaming
             a category that holds four hundred items is a different act from
             renaming an empty one, and the form should say which it is. --}}
        @if($editingId && $this->editingSummary())
          <p class="tb-out-none">{{ $this->editingSummary() }}</p>
        @endif
        {{-- The unit is what everything in this category is counted in, and
             every item inherits it. Picking from what a store actually uses is
             one click and stops "tabs", "tab" and "Tablet" becoming three. --}}
        <x-ui.field label="Counted in" for="cat-unit" name="unit" required>
          <input id="cat-unit" type="text" wire:model.live.debounce.300ms="unit" class="tb-input" required>
          <x-ui.suggestions set="unit" :current="$unit"
                            :options="['tablet', 'capsule', 'bottle', 'vial', 'ampoule', 'sachet', 'tube', 'box', 'piece', 'pair', 'ml', 'litre']" />
        </x-ui.field>
        <x-ui.field label="Description" for="cat-desc" name="description">
          <textarea id="cat-desc" wire:model="description" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create category' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter categories" noun="categories" import-label="Import categories"
    :columns="[['key' => 'unit', 'label' => 'Unit', 'edit' => true, 'type' => 'text']]" />

  @include('livewire.partials.stock-category-peek')
</div>
