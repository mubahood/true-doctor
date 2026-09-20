<?php

namespace App\Enums;

/**
 * Where a piece of work has got to.
 *
 * Deliberately the same four words a visit uses, so staff learn one vocabulary:
 * raised, being done, done, or called off.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-warn',
            self::InProgress => 'badge-info',
            self::Completed => 'badge-success',
            self::Cancelled => 'badge-danger',
        };
    }

    /** @return list<self> */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitionsTo(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }

    /** A cancelled order is off the bill; everything else counts. */
    public function isBillable(): bool
    {
        return $this !== self::Cancelled;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
