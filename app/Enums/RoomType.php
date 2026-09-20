<?php

namespace App\Enums;

enum RoomType: string
{
    case Consultation = 'consultation';
    case Ward = 'ward';
    case Theatre = 'theatre';
    case Lab = 'lab';
    case Radiology = 'radiology';
    case Pharmacy = 'pharmacy';
    case Office = 'office';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'Consultation',
            self::Ward => 'Ward',
            self::Theatre => 'Theatre',
            self::Lab => 'Laboratory',
            self::Radiology => 'Radiology',
            self::Pharmacy => 'Pharmacy',
            self::Office => 'Office',
            self::Other => 'Other',
        };
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $case) => $c + [$case->value => $case->label()], []);
    }
}
