<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Reminder for an upcoming appointment (dispatched by appointments:remind). */
class AppointmentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Appointment $appointment) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'appointment_reminder',
            'title' => 'Appointment reminder',
            'message' => "Reminder: appointment on {$this->appointment->scheduled_at->format('d M Y H:i')}.",
            'appointment_uuid' => $this->appointment->uuid,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Reminder: your appointment is on {$this->appointment->scheduled_at->format('d M Y \a\t H:i')}.";
    }
}
