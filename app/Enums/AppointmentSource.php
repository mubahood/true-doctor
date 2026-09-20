<?php

namespace App\Enums;

enum AppointmentSource: string
{
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case Online = 'online';
    case Referral = 'referral';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Phone => 'Phone',
            self::Online => 'Online',
            self::Referral => 'Referral',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
