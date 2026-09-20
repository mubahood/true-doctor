<?php

namespace App\Enums;

enum CardEntryType: string
{
    case Credit = 'credit';   // top-up / deposit onto the card
    case Debit = 'debit';     // charge against the card

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
        };
    }

    /** Signed multiplier applied to the running balance. */
    public function sign(): int
    {
        return $this === self::Credit ? 1 : -1;
    }
}
