<?php

namespace App\Support;

use App\Models\Hospital;

/**
 * A phone number as a gateway wants it (+E.164), from however the desk typed
 * it: "0772 123 456", "772123456", "+256 772 123456", "256772123456".
 *
 * The country is the hospital's, read from its currency until hospitals carry
 * a country of their own: a Kenyan clinic's 0712… is +254, not +256. A number
 * that cannot be a mobile number after that (too short, too long) is null, so
 * nothing is sent to a guess.
 */
final class PhoneNumber
{
    /** Currency → country calling code. */
    private const CALLING_CODES = ['UGX' => '256', 'KES' => '254', 'TZS' => '255', 'RWF' => '250', 'BIF' => '257', 'SSP' => '211', 'CDF' => '243'];

    public static function e164(?string $phone, ?string $callingCode = '256'): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);
        $code = $callingCode ?: '256';

        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');
        } elseif (str_starts_with($digits, $code) && strlen($digits) > 10) {
            // Already international, written without the plus.
        } elseif (str_starts_with($digits, '0')) {
            $digits = $code.substr($digits, 1);
        } else {
            $digits = $code.$digits;
        }

        // East African mobiles are 12 digits with the country code; E.164 allows 8 to 15.
        return strlen($digits) >= 11 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    public static function callingCodeFor(?Hospital $hospital): string
    {
        return self::CALLING_CODES[strtoupper((string) $hospital?->currency)] ?? '256';
    }

    /** The last nine digits: the part every way of writing the same number shares. */
    public static function tail(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
