<?php

namespace App\Support;

use App\Models\User;

/**
 * Is the person using this the demonstration, or a real hospital?
 *
 * Asked all over the panel to decide whether to show somebody the invitation
 * to start their own hospital. It has to be right in both directions: a real
 * hospital must never be told it is a demo, and a visitor must never be left
 * exploring one without being told what it is.
 *
 * The test is the TENANT, not the email address. Anybody signed into the
 * demonstration hospital is in the demonstration — including a staff account
 * a visitor created while they were poking around, which has no `@test.com`
 * address and is still every bit as temporary as the rest of it.
 */
final class Demo
{
    /** Is there a demonstration on this installation at all? */
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled')
            || app()->environment(['local', 'demo']);
    }

    /** The slug of the hospital that is the demonstration. */
    public static function hospitalSlug(): string
    {
        return (string) config('demo.hospital', 'general-hospital-a');
    }

    /**
     * Is this person inside the demonstration hospital?
     *
     * False for everybody when the demo is switched off, so a real
     * deployment that never turns it on cannot show the invitation by
     * accident — and false for the platform super admin, who belongs to no
     * hospital and is not demonstrating anything.
     */
    public static function isDemoUser(?User $user): bool
    {
        if ($user === null || ! self::enabled()) {
            return false;
        }

        if ($user->hospital_id === null) {
            return false;
        }

        return $user->hospital?->slug === self::hospitalSlug();
    }

    /** Whether the demonstration is emptied and rebuilt on a schedule. */
    public static function resets(): bool
    {
        return self::enabled() && (bool) config('demo.reset.enabled');
    }

    /** "03:30", for a sentence that tells somebody when their work goes. */
    public static function resetsAt(): string
    {
        return (string) config('demo.reset.at', '03:30');
    }
}
