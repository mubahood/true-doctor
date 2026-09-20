<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\WithTable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The staff member's in-app notification list (database channel) — Board shape:
 * polled while visible, mark-read / mark-all-read as optimistic Livewire actions
 * (idempotent toggles, house rule 12). Every read dispatches 'notifications-read'
 * so Shell\NotificationBell drops its cached count immediately.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithTable;

    public function mount(): void
    {
        $this->perPage = 20;
    }

    public function markRead(string $id): void
    {
        $note = Auth::user()?->notifications()->whereKey($id)->first();
        if ($note === null) {
            return; // someone else's notification, or already gone
        }

        $note->markAsRead();
        $this->dispatch('notifications-read');
        $this->dispatch('toast', message: 'Marked as read.', type: 'success');
    }

    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();
        $this->dispatch('notifications-read');
        $this->dispatch('toast', message: 'All notifications marked as read.', type: 'success');
    }

    public function render()
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        return view('livewire.notifications.index', [
            'rows' => $user->notifications()->paginate($this->perPage),
            'unread' => $user->unreadNotifications()->count(),
        ])->title('Notifications');
    }
}
