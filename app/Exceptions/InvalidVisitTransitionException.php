<?php

namespace App\Exceptions;

use App\Enums\VisitStage;
use RuntimeException;

/**
 * A visit was asked to move somewhere it cannot go.
 *
 * Its message is shown to the reader as it stands, so it says what is in the
 * way rather than naming a rule: "2 orders are still open", not "invalid
 * transition". The gates in VisitService produce that wording.
 */
class InvalidVisitTransitionException extends RuntimeException
{
    public static function shut(VisitStage $from, ?VisitStage $to, string $because): self
    {
        return new self($because);
    }

    public static function between(VisitStage $from, VisitStage $to): self
    {
        return new self("Cannot move a visit from {$from->label()} to {$to->label()}.");
    }
}
