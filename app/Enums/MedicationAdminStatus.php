<?php

namespace App\Enums;

enum MedicationAdminStatus: string
{
    case Given = 'given';
    case Withheld = 'withheld';
    case Refused = 'refused';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Given => 'badge-success',
            self::Withheld => 'badge-warn',
            self::Refused => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
