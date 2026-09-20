<div>
  <h1 class="sr-only">Staff profiles</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name, specialty or licence…" aria-label="Search staff profiles">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\StaffProfile::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New profile</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Name</th><th>Role</th><th>Department</th><th>Specialty</th><th>Licence</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="staff-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the profile over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this profile">{{ $r->user?->name ?? '—' }}</button>
          </td>
          <td class="muted">{{ $r->user?->role ?? '—' }}</td>
          <td class="muted">{{ $r->department?->name ?? '—' }}</td>
          <td class="muted">{{ $r->specialty ?? '—' }}</td>
          <td class="mono muted">{{ $r->license_no ?? '—' }}</td>
          <td><x-ui.badge :tone="$r->is_active ? 'active' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See the profile for {{ $r->user?->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit profile for {{ $r->user?->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Archive profile for {{ $r->user?->name }}" icon="fa-box-archive" wire:click="delete({{ $r->id }})" wire:confirm="Archive this profile?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-user-doctor" noun="staff profiles" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit profile' : 'New profile'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Staff member" for="sp-user" name="user_id" required
          :hint="$editingId ? 'The linked user cannot be changed after the profile is created.' : null">
          @if($editingId)
            {{-- Locked after creation, so it is shown rather than offered. --}}
            <input type="text" class="tb-input" disabled
                   value="{{ $this->linkedUser?->name ?? '—' }}">
          @else
            <livewire:ui.select-search resource="staff" name="user_id" :selected="$user_id"
              placeholder="Search staff…" :key="'sp-user-'.$formNonce" />
          @endif
        </x-ui.field>
        <x-ui.field label="Department" name="department_id">
          <livewire:ui.select-search resource="departments" name="department_id" :selected="$department_id"
            placeholder="Search departments…" :key="'sp-dept-'.$formNonce" />
        </x-ui.field>
        <x-ui.field label="Job title" for="sp-job" name="job_title">
          <input id="sp-job" type="text" wire:model="job_title" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Specialty" for="sp-spec" name="specialty">
          <input id="sp-spec" type="text" wire:model="specialty" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Licence no." for="sp-lic" name="license_no">
          <input id="sp-lic" type="text" wire:model="license_no" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Qualifications" for="sp-qual" name="qualifications">
          <textarea id="sp-qual" wire:model="qualifications" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create profile' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.staff-peek')
</div>
