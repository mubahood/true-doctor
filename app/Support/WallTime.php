<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Times a person typed (an appointment's start) are the clinic's wall-clock
 * time, stored as typed (docs/decisions.md, 2026-10-15, standalone time
 * zone). On a server whose clock is the clinic's, that is the instant. On the
 * online service the clock is UTC, so 09:00 in the database is 09:00 in
 * Kampala, not 09:00 UTC: reading it with setTimezone() would move it three
 * hours. This reads it as what it is.
 */
final class WallTime
{
    /** The instant a stored wall-clock time means, in the hospital's zone. */
    public static function at(CarbonInterface $stored, string $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $stored->format('Y-m-d H:i:s'), $zone);
    }
}
