<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Concerns\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Reminder as a free trial nears its end (dispatched by subscriptions:trial-reminders). */
class TrialEnding extends Notification implements ShouldQueue
{
    use AppNotification, Queueable;

    public function __construct(public readonly Subscription $subscription, public readonly int $daysLeft) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return $this->withPush(['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your trial ends in {$this->daysLeft} day(s)")
            ->greeting("Hi {$notifiable->name},")
            ->line('Your True-Doctor free trial ends on '.$this->subscription->trial_ends_at?->format('d M Y').'.')
            ->line('Activate a plan now to keep uninterrupted access to your hospital.')
            ->action('Choose a plan', route('admin.subscription.index'))
            ->line(\App\Support\SupportContact::platform()->mailLine('admin.renew', ['hospital' => $notifiable->hospital->name ?? 'our hospital']));
    }

    /** @return array<string,mixed> */
    protected function legacyData(): array
    {
        return [
            'type' => 'trial_ending',
            'title' => 'Trial ending soon',
            'message' => "Your trial ends in {$this->daysLeft} day(s). Activate a plan to keep access.",
        ];
    }

    protected function category(): string
    {
        return 'account';
    }

    protected function priority(): string
    {
        return $this->daysLeft <= 1 ? 'urgent' : 'normal';
    }

    protected function publicMessage(): string
    {
        return 'Your trial is ending soon.';
    }

    protected function routeName(): ?string
    {
        return 'admin.subscription.index';
    }

    protected function entityType(): ?string
    {
        return 'subscription';
    }

    protected function entityUuid(): ?string
    {
        return null;
    }

    protected function dedupeKey(): ?string
    {
        return 'subscription:trial:'.$this->daysLeft;
    }

    protected function expiresAt(): ?\Carbon\CarbonInterface
    {
        return $this->subscription->trial_ends_at;
    }
}
