<?php

namespace App\Exceptions;

use RuntimeException;

class BedUnavailableException extends RuntimeException
{
    public static function make(string $bed): self
    {
        return new self("Bed {$bed} is not available.");
    }
}
