<?php

namespace App\Enums;

/**
 * Insurance claim lifecycle (HMS_PLAN.md §4). Enforced by InsuranceService; a
 * claim only records a payment on its invoice when it reaches Paid.
 */
enum ClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-info',
            self::Submitted, self::Approved => 'badge-warn',
            self::Paid => 'badge-success',
            self::Rejected, self::Cancelled => 'badge-danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Rejected, self::Cancelled], true);
    }

    /** @return list<self> */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [self::Paid, self::Rejected],
            self::Paid, self::Rejected, self::Cancelled => [],
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
