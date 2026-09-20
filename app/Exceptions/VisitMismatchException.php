<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Work was about to be raised on somebody else's visit.
 *
 * A stay, like every other order, hangs off the visit of the patient it is
 * for. Nothing used to check that, so a visit id from anywhere in the hospital
 * was accepted and one patient's bed charge landed on another patient's bill.
 *
 * Its own class rather than a bare RuntimeException so the screen can put the
 * message beside the field that caused it — which is the visit picker, not the
 * bed and not the patient.
 */
class VisitMismatchException extends RuntimeException
{
    public static function make(): self
    {
        return new self('That visit belongs to a different patient.');
    }
}
