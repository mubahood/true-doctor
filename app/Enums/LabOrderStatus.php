<?php

namespace App\Enums;

/**
 * Lab order lifecycle. Enforced by LabService::transition — the sample is
 * collected, processed, then results are released (completed). Cancel from any
 * pre-completion state.
 */
enum LabOrderStatus: string
{
    case Ordered = 'ordered';
    case Collected = 'collected';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ordered => 'badge-info',
            self::Collected, self::Processing => 'badge-warn',
            self::Completed => 'badge-success',
            self::Cancelled => 'badge-danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return list<self> */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Ordered => [self::Collected, self::Cancelled],
            self::Collected => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
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
