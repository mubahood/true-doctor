<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially paid',
            default => ucfirst($this->value),
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-info',
            self::Issued => 'badge-warn',
            self::PartiallyPaid => 'badge-warn',
            self::Paid => 'badge-success',
            self::Void => 'badge-danger',
        };
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
