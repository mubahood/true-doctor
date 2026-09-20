<?php

namespace App\Exceptions;

use RuntimeException;

class PlanLimitExceededException extends RuntimeException
{
    public static function make(string $resource, int $limit): self
    {
        return new self("Your plan's limit for {$resource} ({$limit}) has been reached. Upgrade to add more.");
    }
}
