<?php

namespace App\Enums;

enum DoseRecordStatus: string
{
    case Pending = 'pending';
    case Administered = 'administered';
    case Missed = 'missed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-info',
            self::Administered => 'badge-success',
            self::Missed => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
