<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised when a card debit would exceed the available balance + credit limit. */
class InsufficientFundsException extends RuntimeException
{
    public static function forCard(string $requested, string $available): self
    {
        return new self("Insufficient card funds: requested {$requested}, available {$available}.");
    }
}
