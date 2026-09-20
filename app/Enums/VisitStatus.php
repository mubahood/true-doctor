<?php

namespace App\Enums;

/**
 * Is this visit alive? — see docs/visits.md.
 *
 * One of the two questions the old eight-value field answered at once. This
 * one is about the visit's life and nothing else; what is being DONE to it is
 * VisitStage, and how a finished one ended is VisitOutcome.
 */
enum VisitStatus: string
{
    case Pending = 'pending';
    case Ongoing = 'ongoing';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ongoing => 'Ongoing',
            self::Completed => 'Completed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-warn',
            self::Ongoing => 'badge-info',
            self::Completed => 'badge-success',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Completed;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
