<?php

namespace App\Enums;

/** A planned procedure's life: planned → accepted → in progress → done (or cancelled before done). */
enum DentalTreatmentStatus: string
{
    case Planned = 'planned';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Accepted => 'Accepted',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Planned => 'neutral',
            self::Accepted => 'info',
            self::InProgress => 'warn',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return list<self> where this status may move next */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Planned => [self::Accepted, self::InProgress, self::Done, self::Cancelled],
            self::Accepted => [self::InProgress, self::Done, self::Cancelled],
            self::InProgress => [self::Done, self::Cancelled],
            self::Done, self::Cancelled => [],
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Cancelled], true);
    }
}
