<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested appointment time falls outside the doctor's
 * availability template, is misaligned to a slot boundary, or overlaps an
 * existing booking for the same doctor or room.
 */
class SlotUnavailableException extends RuntimeException
{
    public static function outsideAvailability(): self
    {
        return new self('The doctor is not available at that time.');
    }

    public static function misaligned(int $slotMinutes): self
    {
        return new self("The start time must align to a {$slotMinutes}-minute slot.");
    }

    public static function doctorBusy(): self
    {
        return new self('The doctor already has an appointment overlapping that time.');
    }

    public static function roomBusy(): self
    {
        return new self('The room is already booked for that time.');
    }
}
