<a wire:navigate href="{{ route('admin.notifications.index') }}"
   class="tb-topbar-bell {{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}"
   wire:poll.60s.visible="refreshCount"
   aria-label="Notifications{{ $this->unread ? ' ('.$this->unread.' unread)' : '' }}" title="Notifications">
  <i class="fas fa-bell" aria-hidden="true"></i>
  @if($this->unread)<span class="tb-bell-badge">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>@endif
</a>
