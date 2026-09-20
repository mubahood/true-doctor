<div>
  <h1 class="sr-only">Price list</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search service or code…" aria-label="Search services">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Service::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New service</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Service</th><th>Code</th><th class="tb-text-right">Price</th><th>Tax</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="services-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the service over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this service">{{ $r->name }}</button>
          </td>
          <td class="mono muted">{{ $r->code ?? '—' }}</td>
          <td class="mono tb-text-right">{{ \App\Support\HospitalSettings::money($r->price) }}</td>
          <td class="muted">{{ $r->tax_exempt ? 'Exempt' : 'Taxable' }}</td>
          <td><x-ui.badge :tone="$r->is_active ? 'active' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive {{ $r->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this service?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-tags" noun="services" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit service' : 'New service'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="svc-name" name="name" required>
          <input id="svc-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Code" for="svc-code" name="code">
          <input id="svc-code" type="text" wire:model="code" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Price" for="svc-price" name="price" required>
          <input id="svc-price" type="number" step="any" wire:model="price" class="tb-input" required>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="tax_exempt"> Tax exempt</label>
        </div>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create service' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter services" noun="services" import-label="Import services"
    :columns="[['key' => 'price', 'label' => 'Price', 'edit' => true, 'type' => 'number', 'align' => 'right']]" />

  @include('livewire.partials.service-peek')
</div>
