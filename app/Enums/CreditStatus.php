<?php

namespace App\Enums;

/**
 * Where a credit note or refund stands. Asked for, then decided once:
 * approved (numbered, on the invoice, in the books) or rejected. Nothing
 * moves out of approved or rejected.
 */
enum CreditStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-warn',
            self::Approved => 'badge-success',
            self::Rejected => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
