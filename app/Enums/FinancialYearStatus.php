<?php

namespace App\Enums;

enum FinancialYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return $this === self::Open ? 'badge-success' : 'badge-warn';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
