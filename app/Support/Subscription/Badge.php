<?php

namespace App\Support\Subscription;

/**
 * What the header badge says about a hospital's subscription: one short label
 * the admin reads at a glance, and one sentence explaining why it matters.
 * Built by App\Support\SubscriptionState — the same object that answers the
 * gate, so the badge can never promise something the gate would refuse.
 */
final readonly class Badge
{
    /**
     * @param  string  $label  the text on the badge itself
     * @param  string  $tone  ok | warn | danger | primary
     * @param  string  $nudge  the reason, on hover and for screen readers
     * @param  bool  $urgent  something the admin should act on now
     */
    public function __construct(
        public string $label,
        public string $tone,
        public string $nudge,
        public bool $urgent = false,
    ) {}

    public function icon(): string
    {
        return match ($this->tone) {
            'danger' => 'fa-triangle-exclamation',
            'warn' => 'fa-clock',
            'primary' => 'fa-star',
            default => 'fa-circle-check',
        };
    }
}
