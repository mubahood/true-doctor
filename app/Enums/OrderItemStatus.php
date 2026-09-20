<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case Ordered = 'ordered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ordered => 'badge-info',
            self::Completed => 'badge-success',
            self::Cancelled => 'badge-danger',
        };
    }

    /** A line that still counts toward the bill (not cancelled). */
    public function isBillable(): bool
    {
        return $this !== self::Cancelled;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
