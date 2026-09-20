<?php

namespace App\Enums;

enum BedStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Available => 'badge-success',
            self::Occupied => 'badge-warn',
            self::Maintenance => 'badge-danger',
        };
    }

    public function isAssignable(): bool
    {
        return $this === self::Available;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
