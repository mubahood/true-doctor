<?php

namespace App\Listeners;

use App\Events\LabResultReleased;
use App\Events\StockBelowReorder;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\LabResultReady;
use App\Notifications\LowStockAlert;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a domain event into notifications. Who hears about it is decided here
 * (by permission, not by role name), and a person who switched the category
 * off is skipped. Services raise events and know nothing of notifications.
 *
 * Not queued: finding recipients is a couple of cheap queries and the
 * notifications themselves are queued. Laravel finds the `handle*` methods
 * by their event type; they are listed in docs/api/notify.md.
 */
class NotifyUser
{
    public function handleLabResultReleased(LabResultReleased $event): void
    {
        $order = $event->order;
        $order->loadMissing('orderedBy');
        if ($order->orderedBy === null) {
            return;
        }

        $this->send(collect([$order->orderedBy]), 'clinical', new LabResultReady($order));
    }

    public function handleStockBelowReorder(StockBelowReorder $event): void
    {
        $item = $event->item;
        // Whoever may manage stock in THIS clinic, after its own choices in
        // Staff and access: asked of each account, not read off the role table.
        $recipients = User::where('hospital_id', $item->hospital_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->can('pharmacy.manage'))
            ->values();

        $this->send($recipients, 'stock', new LowStockAlert($item));
    }

    /** @param  Collection<int,User>  $recipients */
    private function send(Collection $recipients, string $category, BaseNotification $notification): void
    {
        $recipients = $recipients->filter(fn (User $u) => NotificationPreference::allows($u, $category))->values();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
