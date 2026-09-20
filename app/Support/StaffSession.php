<?php

namespace App\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * How long a signed-in member of staff stays signed in.
 *
 * A ward machine is signed in once and used for a season. Making somebody find
 * a password again every fortnight does not make a hospital safer — it makes
 * the password get written on the monitor. So "keep me signed in" is on by
 * default and lasts a quarter, and the session cookie that carries the
 * short-lived half of it is HttpOnly and rotated on every sign-in.
 *
 * What this does NOT do is weaken the check itself. The remember cookie is
 * Laravel's: a random token stored hashed-by-comparison on the user row, tied
 * to that one user, invalidated the moment anybody logs out of that account
 * anywhere (`logout()` cycles the token). A stolen cookie is a stolen session,
 * exactly as before — it simply lasts longer, which is the trade the hospital
 * asked for, and it is still revocable from one place.
 */
final class StaffSession
{
    /** How long the "keep me signed in" cookie lives. */
    public const REMEMBER_DAYS = 90;

    /**
     * Apply the remember window to the web guard.
     *
     * Called immediately before an attempt rather than set once at boot:
     * resolving the session guard during boot would need a session that does
     * not exist yet on a console command or a queued job.
     */
    public static function apply(): void
    {
        $guard = Auth::guard('web');

        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration(self::REMEMBER_DAYS * 24 * 60);
        }
    }

    /** "90 days", for a screen that has to tell somebody what they are agreeing to. */
    public static function rememberLabel(): string
    {
        return self::REMEMBER_DAYS.' days';
    }
}
