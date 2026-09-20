<div>
  <h1 class="sr-only">Beds</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search bed or ward…" aria-label="Search beds">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Bed::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New bed</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Bed</th><th>Ward</th><th class="tb-text-right">Nightly</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="beds-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the bed over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this bed">{{ $r->name }}</button>
          </td>
          <td class="muted">{{ $r->ward?->name ?? '—' }}</td>
          <td class="tb-text-right"><x-ui.money :amount="$r->daily_charge" /></td>
          <td><x-ui.badge :tone="$r->status->badge()">{{ $r->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Delete {{ $r->name }}" icon="fa-trash" wire:click="delete({{ $r->id }})" wire:confirm="Delete this bed?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-bed" noun="beds" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit bed' : 'New bed'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Ward" for="bed-ward" name="ward_id" required>
          <select id="bed-ward" wire:model="ward_id" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->wards as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Name" for="bed-name" name="name" required>
          <input id="bed-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Nightly charge" for="bed-charge" name="daily_charge" required>
          <input id="bed-charge" type="number" step="any" min="0" wire:model="daily_charge" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Status" for="bed-status" name="status" required>
          <select id="bed-status" wire:model="status" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
          </select>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create bed' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.bed-record-peek')
</div>
