<?php

namespace App\Enums;

/**
 * Day of week, values matching Carbon's dayOfWeek (0 = Sunday … 6 = Saturday)
 * so a scheduled_at can be mapped to a template window without conversion.
 */
enum Weekday: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }

    /** @return array<int,string> value => label, Monday-first for display */
    public static function options(): array
    {
        $order = [self::Monday, self::Tuesday, self::Wednesday, self::Thursday, self::Friday, self::Saturday, self::Sunday];

        return array_reduce($order, fn ($c, $d) => $c + [$d->value => $d->label()], []);
    }
}
