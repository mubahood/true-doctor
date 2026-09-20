<?php

namespace App\Notifications\Channels;

use App\Services\Channels\VerificationChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel that sends via the app's swappable SMS transport
 * (VerificationChannel — LogChannel by default, a live gateway in production).
 * A notification opts in by implementing toSms() and the notifiable exposing a
 * phone via routeNotificationForSms() or a `phone` attribute.
 */
class SmsChannel
{
    public function __construct(private readonly VerificationChannel $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }
        $phone = $notifiable->routeNotificationFor('sms', $notification) ?? ($notifiable->phone ?? null);
        if (! $phone) {
            return;
        }

        $this->sms->send((string) $phone, (string) $notification->toSms($notifiable));
    }
}
