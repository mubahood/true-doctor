<?php

namespace App\Enums;

enum PatientStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deceased = 'deceased';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Deceased => 'Deceased',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'badge-active',
            self::Inactive => 'badge-neutral',
            self::Deceased => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), function (array $c, self $s) {
            $c[$s->value] = $s->label();

            return $c;
        }, []);
    }
}
