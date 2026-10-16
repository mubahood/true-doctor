<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one password rule, and the words that describe it.
 *
 * At least 8 characters with letters and numbers, and, where the server can
 * ask, not a password already published in a breach (Have I Been Pwned's
 * k-anonymity range API: only the first five characters of the SHA-1 hash
 * leave the server). The breach check fails open: a server with no internet
 * still lets a person set a password.
 *
 * Every screen that asks for a new password shows {@see hint()}, so the words
 * can never disagree with the rule.
 */
final class PasswordPolicy
{
    public const MIN = 8;

    public static function rule(): Password
    {
        $rule = Password::min(self::MIN)->letters()->numbers();

        return config('auth.password_breach_check') ? $rule->uncompromised() : $rule;
    }

    public static function hint(): string
    {
        return 'At least '.self::MIN.' characters, with letters and numbers.';
    }
}
