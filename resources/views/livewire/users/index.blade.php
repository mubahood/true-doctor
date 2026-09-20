<div>
  <h1 class="sr-only">User accounts</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or email…" aria-label="Search users">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\User::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New user</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Joined</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $u)
        <tr wire:key="user-{{ $u->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td>
            <div class="tb-flex">
              @if($u->avatar_url)
                <img src="{{ $u->avatar_url }}" alt="" style="width:34px;height:34px;object-fit:cover;flex-shrink:0;border:1px solid var(--line);border-radius:4px;">
              @else
                <div class="mono" aria-hidden="true" style="width:34px;height:34px;background:var(--br-soft);display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:600;color:var(--br);flex-shrink:0;border-radius:4px;">{{ $u->initials }}</div>
              @endif
              <div>
                {{-- Opens the account over the list, like everywhere else. --}}
                <div class="tb-fw-500">
                  <button type="button" class="tb-rowbtn" wire:click="peek({{ $u->id }})"
                          title="See this account">{{ $u->name }}</button>
                </div>
                @if($u->id === auth()->id())<x-ui.badge tone="neutral" class="tb-xs">You</x-ui.badge>@endif
              </div>
            </div>
          </td>
          <td>{{ $u->email }}</td>
          <td>
            @php($roleTone = in_array($u->role, ['super_admin','hospital_admin']) ? 'info' : (in_array($u->role, ['doctor','nurse']) ? 'active' : ($u->role === 'accountant' ? 'warn' : 'neutral')))
            <x-ui.badge :tone="$roleTone">{{ $u->role_label }}</x-ui.badge>
          </td>
          <td class="muted">{{ $u->phone ?? '—' }}</td>
          <td><x-ui.badge :tone="$u->is_active ? 'active' : 'danger'">{{ $u->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="muted tb-xs">{{ $u->created_at->format('d M Y') }}</td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $u->name }}" icon="fa-eye" wire:click="peek({{ $u->id }})" />
            @can('update', $u)<x-ui.icon-button label="Edit {{ $u->name }}" icon="fa-pen" wire:click="edit({{ $u->id }})" />@endcan
            @if($u->id !== auth()->id())
              @can('delete', $u)<x-ui.icon-button label="Delete {{ $u->name }}" icon="fa-trash" wire:click="delete({{ $u->id }})" wire:confirm="Delete user {{ $u->name }}?" />@endcan
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-user-shield" noun="users" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal size="xl" show="showForm" :title="$editingId ? 'Edit user' : 'New user'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Full name" for="usr-name" name="name" required>
          <input id="usr-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Email" for="usr-email" name="email" required>
          <input id="usr-email" type="email" wire:model="email" class="tb-input" required>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Role" for="usr-role" name="role" required>
            <select id="usr-role" wire:model="role" class="tb-select">
              <option value="">— select —</option>
              @foreach($this->roles as $r)<option value="{{ $r }}">{{ \Illuminate\Support\Str::of($r)->replace('_',' ')->title() }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Phone" for="usr-phone" name="phone">
            <input id="usr-phone" type="text" wire:model="phone" class="tb-input">
          </x-ui.field>
        </div>
        <x-ui.field label="Bio" for="usr-bio" name="bio">
          <textarea id="usr-bio" wire:model="bio" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <x-ui.field label="Avatar" for="usr-avatar" name="avatar" hint="JPG, PNG or WebP, up to 10 MB.">
          <input id="usr-avatar" type="file" wire:model.live="avatar" accept="image/jpeg,image/png,image/webp" class="tb-input">
          <div wire:loading wire:target="avatar" class="muted tb-small tb-flex tb-mt-2" role="status"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Uploading…</div>
          @if($avatar)
            {{-- A rejected upload (e.g. a PDF the temporary-upload rules still allow) has no preview URL. --}}
            @if($avatar->isPreviewable())
              <img src="{{ $avatar->temporaryUrl() }}" alt="Avatar preview" class="tb-mt-2" style="width:56px;height:56px;object-fit:cover;border:1px solid var(--line);border-radius:4px;">
            @endif
          @elseif($editingId)
            <label class="tb-check-group tb-mt-2"><input type="checkbox" wire:model="remove_avatar"> Remove current avatar</label>
          @endif
        </x-ui.field>
        @if($editingId)
          <div class="tb-form-grid">
            <x-ui.field label="New password" for="usr-pw" name="password" hint="Leave blank to keep the current password.">
              <input id="usr-pw" type="password" wire:model="password" class="tb-input" autocomplete="new-password">
            </x-ui.field>
            <x-ui.field label="Confirm password" for="usr-pw2" name="password_confirmation">
              <input id="usr-pw2" type="password" wire:model="password_confirmation" class="tb-input" autocomplete="new-password">
            </x-ui.field>
          </div>
          <p class="muted tb-small"><i class="fas fa-circle-info" aria-hidden="true"></i> A reset forces the user to choose a new password at next login.</p>
        @else
          <p class="muted tb-small"><i class="fas fa-circle-info" aria-hidden="true"></i> A temporary password is generated and emailed to the user; they set their own at first login.</p>
        @endif
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save,avatar">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create user' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.user-peek')
</div>
