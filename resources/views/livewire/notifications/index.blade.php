<div wire:poll.60s.visible>
  <x-ui.page-header title="Notifications" :crumbs="['Dashboard' => route('admin.dashboard'), 'Notifications' => null]">
    <x-slot:actions>
      @if($unread)
        <button type="button" wire:click="markAllRead" class="btn-tb" wire:loading.attr="disabled" wire:target="markAllRead">
          <i class="fas fa-check-double" aria-hidden="true"></i> Mark all read
        </button>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-card"><div class="dash-queue">
    @forelse($rows as $n)
      <div class="dash-queue-row" wire:key="notification-{{ $n->id }}">
        <div class="qmain">
          <div class="qname">{{ $n->data['title'] ?? 'Notification' }}
            @unless($n->read_at)<x-ui.badge tone="info">New</x-ui.badge>@endunless
          </div>
          <div class="qmeta">{{ $n->data['message'] ?? '' }}</div>
          <div class="qmeta">{{ $n->created_at->diffForHumans() }}</div>
        </div>
        @unless($n->read_at)
          <button type="button" wire:click="markRead('{{ $n->id }}')" class="btn-tb btn-tb-sm btn-tb-ghost"
            wire:loading.attr="disabled" wire:target="markRead('{{ $n->id }}')">Mark read</button>
        @endunless
      </div>
    @empty
      <x-ui.empty icon="fa-bell-slash" noun="notifications" />
    @endforelse
  </div></div>

  {{ $rows->links(data: $this->paginationData()) }}
</div>
