<?php

namespace App\Enums;

/**
 * How a finished visit ended — see docs/visits.md.
 *
 * Only ever set alongside VisitStatus::Completed. It is separate from the
 * status because "finished" and "finished WELL" are different facts: a
 * cancelled visit and a settled one are both over, and every report that
 * counts attendances needs to tell them apart.
 */
enum VisitOutcome: string
{
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Closed => 'badge-success',
            self::Cancelled => 'badge-danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
