<?php

namespace App\Livewire\Shell;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Topbar notification bell. Replaces the per-request COUNT query the layout
 * used to run: the unread count is polled while the tab is visible and
 * refreshed when a notification is read elsewhere ('notifications-read').
 */
class NotificationBell extends Component
{
    #[Computed]
    public function unread(): int
    {
        return (int) Auth::user()?->unreadNotifications()->count();
    }

    #[On('notifications-read')]
    public function refreshCount(): void
    {
        unset($this->unread);
    }

    public function render()
    {
        return view('livewire.shell.notification-bell');
    }
}
