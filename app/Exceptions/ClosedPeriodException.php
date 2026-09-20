<?php

namespace App\Exceptions;

use RuntimeException;

class ClosedPeriodException extends RuntimeException
{
    public static function make(string $period): self
    {
        return new self("The accounting period “{$period}” is closed; postings dated within it are not allowed.");
    }
}
