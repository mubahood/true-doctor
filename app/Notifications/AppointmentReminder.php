<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Concerns\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Reminder for an upcoming appointment (dispatched hourly by appointments:remind). */
class AppointmentReminder extends Notification implements ShouldQueue
{
    use AppNotification, Queueable;

    /** @param  string  $kind  'day' (the day before) or 'soon' (two hours before) */
    public function __construct(public readonly Appointment $appointment, public readonly string $kind = 'day') {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        // The text message is the day-before one only; the 2-hour nudge is in-app and push.
        return $this->withPush($this->kind === 'day' ? ['database', SmsChannel::class] : ['database']);
    }

    /** @return array<string,mixed> */
    protected function legacyData(): array
    {
        return [
            'type' => 'appointment_reminder',
            'title' => 'Appointment reminder',
            'message' => "Reminder: appointment on {$this->appointment->scheduled_at->format('d M Y H:i')}.",
            'appointment_uuid' => $this->appointment->uuid,
        ];
    }

    protected function category(): string
    {
        return 'schedule';
    }

    protected function publicMessage(): string
    {
        return 'You have an appointment coming up.';
    }

    protected function privateMessage(): ?string
    {
        $who = $this->appointment->patient?->full_name;

        return "Appointment on {$this->appointment->scheduled_at->format('d M Y H:i')}".($who ? " with {$who}." : '.');
    }

    protected function routeName(): ?string
    {
        return 'admin.appointments.index';
    }

    protected function entityType(): ?string
    {
        return 'appointment';
    }

    protected function entityUuid(): ?string
    {
        return $this->appointment->uuid;
    }

    protected function dedupeKey(): ?string
    {
        return 'appt:'.$this->appointment->uuid.':'.$this->kind;
    }

    protected function expiresAt(): ?\Carbon\CarbonInterface
    {
        return $this->appointment->scheduled_at;
    }

    public function toSms(object $notifiable): string
    {
        return "Reminder: your appointment is on {$this->appointment->scheduled_at->format('d M Y \a\t H:i')}.";
    }
}
