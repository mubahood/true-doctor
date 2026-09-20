<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function make(string $item, string $available, string $requested): self
    {
        return new self("Not enough {$item} in stock: {$available} available, {$requested} requested.");
    }
}
