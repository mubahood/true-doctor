<?php

namespace App\Enums;

/**
 * A holder is revoked, never deleted: what they spent stays on the ledger, and
 * a row that vanishes takes the explanation of an old charge with it.
 */
enum CardHolderStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'badge-active',
            self::Revoked => 'badge-neutral',
        };
    }

    public function canSpend(): bool
    {
        return $this === self::Active;
    }
}
