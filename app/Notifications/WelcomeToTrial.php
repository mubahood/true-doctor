<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the hospital owner right after self-registration. */
class WelcomeToTrial extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ends = $this->subscription->trial_ends_at?->format('d M Y');

        return (new MailMessage)
            ->subject('Welcome to True-Doctor — your trial has started')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line('Your hospital account is ready and your 14-day free trial is active'.($ends ? " until {$ends}." : '.'))
            ->line('Finish setting up in a few quick steps, then activate a plan to keep going.')
            ->action('Get started', route('admin.onboarding'))
            ->line('Thanks for choosing True-Doctor.');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome_trial',
            'title' => 'Welcome — trial started',
            'message' => 'Your 14-day free trial is active. Finish onboarding to get going.',
        ];
    }
}
