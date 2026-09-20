<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Reminder as a free trial nears its end (dispatched by subscriptions:trial-reminders). */
class TrialEnding extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription, public readonly int $daysLeft) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your trial ends in {$this->daysLeft} day(s)")
            ->greeting("Hi {$notifiable->name},")
            ->line('Your True-Doctor free trial ends on '.$this->subscription->trial_ends_at?->format('d M Y').'.')
            ->line('Activate a plan now to keep uninterrupted access to your hospital.')
            ->action('Choose a plan', route('admin.subscription.index'));
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_ending',
            'title' => 'Trial ending soon',
            'message' => "Your trial ends in {$this->daysLeft} day(s). Activate a plan to keep access.",
        ];
    }
}
