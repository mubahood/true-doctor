<?php

namespace App\Enums;

/** A pregnancy is open until it ends: in a birth, or otherwise (a loss, a transfer out). Closed is final. */
enum PregnancyStatus: string
{
    case Active = 'active';
    case Delivered = 'delivered';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Current',
            self::Delivered => 'Delivered',
            self::Ended => 'Ended',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Delivered => 'success',
            self::Ended => 'neutral',
        };
    }
}
