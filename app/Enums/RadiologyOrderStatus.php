<?php

namespace App\Enums;

/** Radiology order lifecycle: booked, imaged, then reported. */
enum RadiologyOrderStatus: string
{
    case Ordered = 'ordered';
    case Scheduled = 'scheduled';
    case Performed = 'performed';
    case Reported = 'reported';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ordered => 'badge-info',
            self::Scheduled, self::Performed => 'badge-warn',
            self::Reported => 'badge-success',
            self::Cancelled => 'badge-danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Reported, self::Cancelled], true);
    }

    /** @return list<self> */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Ordered => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Performed, self::Cancelled],
            self::Performed => [self::Reported, self::Cancelled],
            self::Reported, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitionsTo(), true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
