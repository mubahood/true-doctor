<?php

namespace App\Exceptions;

use RuntimeException;

class PlanLimitExceededException extends RuntimeException
{
    public static function make(string $resource, int $limit): self
    {
        $subject = \App\Support\Edition::isStandalone() ? 'licence' : 'plan';
        $action = \App\Support\Edition::isStandalone() ? 'Contact True-Doctor about changing the limit.' : 'Upgrade to add more.';

        return new self("Your {$subject}'s limit for {$resource} ({$limit}) has been reached. {$action}");
    }
}
