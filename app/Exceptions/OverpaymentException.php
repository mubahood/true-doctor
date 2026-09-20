<?php

namespace App\Exceptions;

use RuntimeException;

class OverpaymentException extends RuntimeException
{
    public static function make(string $amount, string $balance): self
    {
        return new self("Payment of {$amount} exceeds the outstanding balance of {$balance}.");
    }
}
