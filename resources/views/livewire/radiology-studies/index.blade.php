<div>
  <h1 class="sr-only">Radiology studies</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search study…" aria-label="Search radiology studies">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\RadiologyStudy::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New study</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Study</th><th>Modality</th><th>Body part</th><th class="tb-text-right">Price</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="radiology-studies-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the study over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this study">{{ $r->name }}</button>
          </td>
          <td class="muted">{{ $r->modality ?? '—' }}</td>
          <td class="muted">{{ $r->body_part ?? '—' }}</td>
          <td class="tb-text-right"><x-ui.money :amount="$r->price" /></td>
          <td><x-ui.badge :tone="$r->is_active ? 'success' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive {{ $r->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this study?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-x-ray" noun="studies" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit study' : 'New study'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="rs-name" name="name" required>
          <input id="rs-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Modality" for="rs-modality" name="modality" hint="e.g. X-Ray, CT, MRI">
          <input id="rs-modality" type="text" wire:model="modality" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Body part" for="rs-body-part" name="body_part">
          <input id="rs-body-part" type="text" wire:model="body_part" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Price" for="rs-price" name="price" required>
          <input id="rs-price" type="number" step="any" min="0" wire:model="price" class="tb-input" required>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create study' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter studies" noun="studies" import-label="Import studies"
    :columns="[['key' => 'modality', 'label' => 'Modality'], ['key' => 'body_part', 'label' => 'Body part'], ['key' => 'price', 'label' => 'Price', 'edit' => true, 'type' => 'number', 'align' => 'right']]" />

  @include('livewire.partials.radiology-study-peek')
</div>
