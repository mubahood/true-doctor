<?php

namespace App\Notifications;

use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\Concerns\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Payment receipt + confirmation when a paid subscription is activated. */
class SubscriptionActivated extends Notification implements ShouldQueue
{
    use AppNotification, Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Plan $plan,
        public readonly string $amount,
    ) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return $this->withPush(['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = \App\Support\PlatformCurrency::format($this->amount);

        return (new MailMessage)
            ->subject("Payment received — {$this->plan->name} plan active")
            ->greeting('Thank you!')
            ->line("Your payment of {$amount} for the {$this->plan->name} plan was received.")
            ->line('Your subscription is active until '.$this->subscription->ends_at?->format('d M Y').'.')
            ->action('View subscription', route('admin.subscription.index'))
            ->line('This email is your receipt.')
            ->line(\App\Support\SupportContact::platform()->mailLine('admin.help', ['role' => 'the owner', 'hospital' => $notifiable->hospital->name ?? 'our hospital', 'screen' => 'my subscription']));
    }

    /** @return array<string,mixed> */
    protected function legacyData(): array
    {
        return [
            'type' => 'subscription_activated',
            'title' => 'Subscription active',
            'message' => "{$this->plan->name} plan active — payment of ".\App\Support\PlatformCurrency::format($this->amount).' received.',
        ];
    }

    protected function category(): string
    {
        return 'billing';
    }

    protected function priority(): string
    {
        return 'low';
    }

    protected function publicMessage(): string
    {
        return 'Your subscription is active.';
    }

    protected function routeName(): ?string
    {
        return 'admin.subscription.index';
    }

    protected function entityType(): ?string
    {
        return 'subscription';
    }
}
