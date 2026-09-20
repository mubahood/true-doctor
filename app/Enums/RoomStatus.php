<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Maintenance = 'maintenance';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Bootstrap-ish badge class used by the admin theme. */
    public function badge(): string
    {
        return match ($this) {
            self::Available => 'badge-success',
            self::Occupied => 'badge-info',
            self::Maintenance => 'badge-warn',
            self::Closed => 'badge-danger',
        };
    }

    /** A room can take new bookings/occupancy only when available. */
    public function isBookable(): bool
    {
        return $this === self::Available;
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $case) => $c + [$case->value => $case->label()], []);
    }
}
