<?php

namespace App\Exceptions;

use App\Enums\AppointmentStatus;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public static function between(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self("Cannot move an appointment from {$from->label()} to {$to->label()}.");
    }
}
