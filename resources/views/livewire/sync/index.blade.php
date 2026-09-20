<div>
  <x-ui.page-header title="Offline devices"
    :crumbs="['Dashboard' => route('admin.dashboard'), 'Offline devices' => null]">
    <x-slot:explain>
      Every browser that has been trusted to keep a copy of this hospital's records so it can work
      without a connection. Blocking a device stops it syncing and clears its copy the next time it
      reaches the server — a machine that never comes back keeps what it has, so treat this as a
      control, not a guarantee.
    </x-slot:explain>
  </x-ui.page-header>

  {{-- The same subject from the other end. Somebody arriving here to ask
       "is MY machine ready?" is in the wrong place, and should be told so
       rather than left reading a fleet list for their own laptop. --}}
  <div class="tb-card tb-mb-4"><div class="tb-card-body tb-flex"
       style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="muted tb-small">
      Checking whether <strong>the machine you are using now</strong> is ready to work without a
      connection is a different question, and it is answered on its own page.
    </span>
    <a class="btn-tb btn-tb-sm" wire:navigate href="{{ route('admin.offline.readiness') }}">
      <i class="fas fa-cloud-arrow-down" aria-hidden="true"></i> Offline readiness
    </a>
  </div></div>

  @php($fig = $this->figures)
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-laptop-medical" :value="$fig['devices']" label="Devices with a copy" />
    <x-dash.stat icon="fa-clock" :tone="$fig['stale'] > 0 ? 'warn' : ''" :value="$fig['stale']"
                 label="Not seen in {{ \App\Livewire\Sync\Index::STALE_DAYS }} days" />
    <x-dash.stat icon="fa-code-branch" :tone="$fig['conflicts'] > 0 ? 'warn' : ''"
                 :value="$fig['conflicts']" label="Conflicts waiting" />
    <x-dash.stat icon="fa-ban" :tone="$fig['revoked'] > 0 ? 'bad' : ''" :value="$fig['revoked']" label="Blocked" />
  </div>

  @if($fig['rejected'] + $fig['failed'] > 0)
    <div class="tb-card tb-mb-4"><div class="tb-card-body">
      <span class="muted tb-small">
        In the last 7 days: <strong>{{ $fig['rejected'] }}</strong>
        {{ Str::plural('operation', $fig['rejected']) }} refused and
        <strong>{{ $fig['failed'] }}</strong> that could not be applied. The work is still on the
        devices that sent it — nothing has been lost — but somebody should look.
      </span>
    </div></div>
  @endif

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Device name or the person using it…" aria-label="Search devices">
    </div>

    <select wire:model.live="state" class="tb-select" aria-label="Filter devices">
      <option value="">Every device</option>
      <option value="active">Working</option>
      <option value="stale">Not seen lately</option>
      <option value="revoked">Blocked</option>
    </select>

    <div wire:loading.flex wire:target="search,state" class="muted tb-small tb-flex" style="gap:6px;align-items:center;">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…
    </div>
  </div>

  <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
    <caption class="sr-only">Devices trusted to hold records offline</caption>
    <thead><tr>
      <th class="tb-serial">#</th>
      <th>Device</th><th>Who uses it</th><th>Last seen</th><th>Last synced</th><th>Status</th>
      <th><span class="sr-only">Actions</span></th>
    </tr></thead>
    <tbody>
      @forelse($rows as $device)
        <tr wire:key="device-{{ $device->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the device over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $device->id }})"
                    title="See this device">{{ $device->label }}</button>
            @if($device->platform)<span class="tb-row-sub">{{ Str::limit($device->platform, 42) }}</span>@endif
          </td>
          <td class="muted">{{ $device->user?->name ?? '—' }}</td>
          <td class="muted tb-nowrap">
            {{ $device->last_seen_at?->diffForHumans() ?? 'never' }}
          </td>
          <td class="muted tb-nowrap">
            {{ $device->last_sync_at?->diffForHumans() ?? 'never' }}
          </td>
          <td>
            @if(! $device->isActive())
              <x-ui.badge tone="danger">Blocked</x-ui.badge>
            @elseif($device->last_seen_at === null || $device->last_seen_at->lt(now()->subDays(\App\Livewire\Sync\Index::STALE_DAYS)))
              <x-ui.badge tone="warn">Not seen lately</x-ui.badge>
            @else
              <x-ui.badge tone="success">Working</x-ui.badge>
            @endif
          </td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $device->label }}" icon="fa-eye" wire:click="peek({{ $device->id }})" />
            <x-ui.actions-menu label="Actions for {{ $device->label }}">
              <button type="button" role="menuitem" wire:click="peek({{ $device->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
              @if($device->isActive())
                <hr>
                <button type="button" role="menuitem" class="danger" wire:click="openRevoke({{ $device->id }})">
                  <i class="fas fa-ban" aria-hidden="true"></i> Block this device
                </button>
              @else
                <hr>
                <button type="button" role="menuitem" wire:click="restore({{ $device->id }})"
                        wire:confirm="Let this device work offline again?">
                  <i class="fas fa-rotate-left" aria-hidden="true"></i> Allow it again
                </button>
              @endif
            </x-ui.actions-menu>
          </td>
        </tr>
      @empty
        <tr><td colspan="7">
          <x-ui.empty icon="fa-laptop-medical" noun="offline devices"
                      :filtered="$search !== '' || $state !== ''" wire:click="$set('search', '')" />
        </td></tr>
      @endforelse
    </tbody>
  </table></div></div>

  {{ $rows->links(data: $this->paginationData()) }}

  @include('livewire.sync.partials.device-peek')
  @include('livewire.sync.partials.revoke')
</div>
