<?php

namespace App\Enums;

/**
 * The four daily administration slots the DosageScheduleGenerator expands a
 * dose item into (the legacy Morning/Afternoon/Evening/Night dosing model).
 */
enum DoseSlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Night = 'night';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Canonical ordering for a day's schedule. */
    public static function ordered(): array
    {
        return [self::Morning, self::Afternoon, self::Evening, self::Night];
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::ordered(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
