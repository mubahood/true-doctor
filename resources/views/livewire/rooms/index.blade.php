<div>
  <h1 class="sr-only">Rooms</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search room…" aria-label="Search rooms">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Room::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New room</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Name</th><th>Type</th><th>Department</th><th>Capacity</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="rooms-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the room over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this room">{{ $r->name }}</button>
          </td>
          <td class="muted">{{ $r->type->label() }}</td>
          <td class="muted">{{ $r->department?->name ?? '—' }}</td>
          <td class="muted">{{ $r->capacity }}</td>
          <td><x-ui.badge :tone="$r->status->badge()">{{ $r->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive {{ $r->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this room?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-door-open" noun="rooms" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit room' : 'New room'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="room-name" name="name" required>
          <input id="room-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Department" for="room-dept" name="department_id">
          <select id="room-dept" wire:model="department_id" class="tb-select">
            <option value="">— none —</option>
            @foreach($this->departments as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Type" for="room-type" name="type" required>
          <select id="room-type" wire:model="type" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->types as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Status" for="room-status" name="status" required>
          <select id="room-status" wire:model="status" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Capacity" for="room-capacity" name="capacity" required>
          <input id="room-capacity" type="number" step="1" min="1" wire:model="capacity" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Notes" for="room-notes" name="notes">
          <textarea id="room-notes" wire:model="notes" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create room' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.room-peek')
</div>
