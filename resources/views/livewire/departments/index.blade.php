<div>
  <h1 class="sr-only">Departments</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or code…" aria-label="Search departments">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Department::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New department</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Name</th><th>Code</th><th>Head</th><th>Rooms</th><th>Staff</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $d)
        <tr wire:key="departments-{{ $d->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the department over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $d->id }})"
                    title="See this department">{{ $d->name }}</button>
          </td>
          <td class="mono muted">{{ $d->code ?? '—' }}</td>
          <td class="muted">{{ $d->head?->name ?? '—' }}</td>
          <td class="muted">{{ $d->rooms_count }}</td>
          <td class="muted">{{ $d->staff_profiles_count }}</td>
          <td><x-ui.badge :tone="$d->is_active ? 'active' : 'danger'">{{ $d->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $d->name }}" icon="fa-eye" wire:click="peek({{ $d->id }})" />
            @can('update', $d)<x-ui.icon-button label="Edit {{ $d->name }}" icon="fa-pen" wire:click="edit({{ $d->id }})" />@endcan
            @can('delete', $d)<x-ui.icon-button label="Archive {{ $d->name }}" icon="fa-box-archive" wire:click="delete({{ $d->id }})" wire:confirm="Archive this department?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-sitemap" noun="departments" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit department' : 'New department'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="dept-name" name="name" required>
          <input id="dept-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Code" for="dept-code" name="code">
          <input id="dept-code" type="text" wire:model="code" class="tb-input" placeholder="e.g. OPD">
        </x-ui.field>
        <x-ui.field label="Head of department" name="head_user_id">
          <livewire:ui.select-search resource="staff" name="head_user_id" :selected="$head_user_id"
            placeholder="Search staff…" :key="'dept-head-'.$formNonce" />
        </x-ui.field>
        <x-ui.field label="Description" for="dept-desc" name="description">
          <textarea id="dept-desc" wire:model="description" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create department' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter departments" noun="departments" import-label="Import departments"
    :columns="[['key' => 'code', 'label' => 'Code', 'edit' => true, 'type' => 'text']]" />

  @include('livewire.partials.department-peek')
</div>
