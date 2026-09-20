<div>
  <h1 class="sr-only">Lab tests</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search test…" aria-label="Search lab tests">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\LabTest::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New test</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Test</th><th>Specimen</th><th>Range</th><th class="tb-text-right">Price</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="lab-tests-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the test over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this test">{{ $r->name }}</button>
          </td>
          <td class="muted">{{ $r->specimen ?? '—' }}</td>
          <td class="muted">{{ $r->reference_range ?? '—' }} {{ $r->unit }}</td>
          <td class="tb-text-right"><x-ui.money :amount="$r->price" /></td>
          <td><x-ui.badge :tone="$r->is_active ? 'success' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive {{ $r->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this test?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-vial" noun="lab tests" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit test' : 'New test'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="lt-name" name="name" required>
          <input id="lt-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Code" for="lt-code" name="code">
          <input id="lt-code" type="text" wire:model="code" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Specimen" for="lt-specimen" name="specimen">
          <input id="lt-specimen" type="text" wire:model="specimen" class="tb-input" placeholder="e.g. Blood">
        </x-ui.field>
        <x-ui.field label="Unit" for="lt-unit" name="unit">
          <input id="lt-unit" type="text" wire:model="unit" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Reference range" for="lt-range" name="reference_range">
          <input id="lt-range" type="text" wire:model="reference_range" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Price" for="lt-price" name="price" required>
          <input id="lt-price" type="number" step="any" min="0" wire:model="price" class="tb-input" required>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create test' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter lab tests" noun="lab tests" import-label="Import tests"
    :columns="[['key' => 'specimen', 'label' => 'Specimen'], ['key' => 'price', 'label' => 'Price', 'edit' => true, 'type' => 'number', 'align' => 'right']]" />

  @include('livewire.partials.lab-test-peek')
</div>
