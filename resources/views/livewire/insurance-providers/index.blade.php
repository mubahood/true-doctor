<div>
  <h1 class="sr-only">Insurance providers</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or code…" aria-label="Search insurance providers">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\InsuranceProvider::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New provider</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Provider</th><th>Contact</th><th>Status</th><th class="tb-text-right">Float held</th><th class="tb-text-right">Members owe</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="insurance-providers-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td class="tb-fw-500">
            {{-- Opens the insurer over the directory, like everywhere else. --}}
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})" title="See this provider">{{ $r->name }}</button>
            <span class="muted mono tb-xs">{{ $r->code }}</span>
          </td>
          <td class="muted">{{ $r->contact_person ?? '—' }} @if($r->contact_phone)· {{ $r->contact_phone }}@endif</td>
          <td><x-ui.badge :tone="$r->is_active ? 'active' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          {{-- The money lives on the provider's own page; these two figures
               are here so the directory says which entry needs opening. --}}
          <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($r->float_balance) }}</td>
          <td class="tb-text-right mono {{ bccomp($r->outstanding(), '0', 2) > 0 ? 'tb-danger' : 'muted' }}">
            {{ \App\Support\HospitalSettings::money($r->outstanding()) }}
          </td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive {{ $r->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this provider?" />@endcan
            <x-ui.icon-button label="Open the full record for {{ $r->name }}" icon="fa-arrow-right"
                              :href="route('admin.insurance-providers.show', $r)" />
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-shield-heart" noun="providers" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit provider' : 'New provider'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="prov-name" name="name" required>
          <input id="prov-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Code" for="prov-code" name="code">
          <input id="prov-code" type="text" wire:model="code" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Contact person" for="prov-person" name="contact_person">
          <input id="prov-person" type="text" wire:model="contact_person" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Contact phone" for="prov-phone" name="contact_phone">
          <input id="prov-phone" type="text" wire:model="contact_phone" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Contact email" for="prov-email" name="contact_email">
          <input id="prov-email" type="email" wire:model="contact_email" class="tb-input">
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create provider' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.provider-peek')
</div>
