<?php

namespace App\Enums;

enum CardStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'badge-active',
            self::Inactive => 'badge-neutral',
            self::Suspended => 'badge-warn',
        };
    }

    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn (array $c, self $s) => $c + [$s->value => $s->label()], []);
    }
}
