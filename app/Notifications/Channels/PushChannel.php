<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use App\Services\Push\PushManager;
use Illuminate\Notifications\Notification;

/**
 * Hands a notification's push payload to the configured transport, once per
 * registered, un-revoked device of the person that has push switched on and a
 * token. Public text only; a person who turned the category off hears nothing.
 */
class PushChannel
{
    public function __construct(private readonly PushManager $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush') || ! isset($notifiable->id)) {
            return;
        }

        $payload = $notification->toPush($notifiable);
        if (! NotificationPreference::allows($notifiable, (string) ($payload['category'] ?? 'system'))) {
            return;
        }

        $transport = $this->push->transport();
        DeviceToken::query()->active()->where('user_id', $notifiable->id)->each(
            fn (DeviceToken $d) => $transport->send((string) $d->token, (string) $d->platform, $payload),
        );
    }
}
