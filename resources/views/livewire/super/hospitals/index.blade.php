<div>
  <h1 class="sr-only">Hospitals</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or slug…" aria-label="Search hospitals">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Hospital::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New hospital</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Name</th><th>Slug</th><th>Staff</th><th>Status</th><th>Subscription</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $h)
        <tr wire:key="hosp-{{ $h->id }}">
          <td class="tb-fw-500">{{ $h->name }}</td>
          <td class="mono muted">{{ $h->slug }}</td>
          <td class="muted">{{ $h->users_count }}</td>
          <td><x-ui.badge :tone="$h->status->value === 'active' ? 'active' : 'neutral'">{{ $h->status->label() }}</x-ui.badge></td>
          <td>
            @php($sub = $h->subscriptions->first())
            @if($sub)
              <x-ui.badge :tone="$sub->status->value === 'active' ? 'active' : ($sub->status->value === 'trialing' ? 'info' : 'neutral')">{{ $sub->status->label() }}</x-ui.badge>
            @else
              <x-ui.badge tone="neutral">None</x-ui.badge>
            @endif
          </td>
          <td class="tb-text-right tb-nowrap">
            @can('update', $h)<x-ui.icon-button label="Edit {{ $h->name }}" icon="fa-pen" wire:click="edit({{ $h->id }})" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-hospital" noun="hospitals" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit hospital' : 'New hospital'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="hosp-name" name="name" required>
          <input id="hosp-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Slug" for="hosp-slug" name="slug" hint="Auto-generated from the name if left blank.">
          <input id="hosp-slug" type="text" wire:model="slug" class="tb-input" placeholder="e.g. city-clinic">
        </x-ui.field>
        <x-ui.field label="Address" for="hosp-address" name="address">
          <textarea id="hosp-address" wire:model="address" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Timezone" for="hosp-tz" name="timezone" required>
            <input id="hosp-tz" type="text" wire:model="timezone" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Currency" for="hosp-currency" name="currency" required>
            <input id="hosp-currency" type="text" wire:model="currency" class="tb-input" maxlength="3" style="text-transform:uppercase;" required>
          </x-ui.field>
          <x-ui.field label="Status" for="hosp-status" name="status" required>
            <select id="hosp-status" wire:model="status" class="tb-select">
              @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
            </select>
          </x-ui.field>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create hospital' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
