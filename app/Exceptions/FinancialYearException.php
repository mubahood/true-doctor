<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused accounting-period transition (overlapping period, inverted dates,
 * closing an already-closed period). Domain-level and expected: the UI catches
 * exactly this class — never \Throwable — and turns it into a toast.
 */
class FinancialYearException extends RuntimeException
{
    public static function overlapping(): self
    {
        return new self('This period overlaps an existing financial year.');
    }

    public static function invalidRange(): self
    {
        return new self('End date must be on or after the start date.');
    }

    public static function alreadyClosed(): self
    {
        return new self('This period is already closed.');
    }
}
