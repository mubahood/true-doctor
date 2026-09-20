<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether a subscription in this status grants access to tenant routes. */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active => true,
            self::Expired, self::Cancelled => false,
        };
    }
}
