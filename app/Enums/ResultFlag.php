<?php

namespace App\Enums;

/** Interpretation flag for a single lab result value. */
enum ResultFlag: string
{
    case Normal = 'normal';
    case Low = 'low';
    case High = 'high';
    case Abnormal = 'abnormal';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Normal => 'badge-success',
            self::Low, self::High => 'badge-warn',
            self::Abnormal => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
