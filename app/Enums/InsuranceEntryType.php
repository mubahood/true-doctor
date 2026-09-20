<?php

namespace App\Enums;

/**
 * One line of an insurer's float ledger (docs/cards.md).
 *
 * A deposit is money the insurer has placed with the hospital. A settlement
 * spends it: the same transaction posts a matching credit onto a member's card,
 * which is how a member's debt is cleared. An adjustment is how a mistake is
 * corrected — a ledger row is never edited or deleted.
 */
enum InsuranceEntryType: string
{
    case Deposit = 'deposit';
    case Settlement = 'settlement';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Settlement => 'Settlement',
            self::Adjustment => 'Adjustment',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Deposit => 'success',
            self::Settlement => 'warn',
            self::Adjustment => 'neutral',
        };
    }

    /**
     * Which way the float moves. An adjustment carries its own sign in the
     * amount, so it is the one entry that is not fixed here.
     */
    public function sign(): ?int
    {
        return match ($this) {
            self::Deposit => 1,
            self::Settlement => -1,
            self::Adjustment => null,
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn (array $c, self $t) => $c + [$t->value => $t->label()], []);
    }
}
