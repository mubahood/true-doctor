<?php

namespace App\Exceptions;

use DomainException;

/** A chart-of-accounts rule refused a change; `field` names the input it belongs to. */
class AccountRuleException extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'account')
    {
        parent::__construct($message);
    }
}
