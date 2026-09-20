<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Human-readable, typo-resistant verification codes:
 *   TD-<CATEGORY_PREFIX>-<YEAR>-<SEQUENCE><CHECK>   e.g. TD-DR-2026-000482K
 *
 * The check character (a mod-N over an unambiguous alphabet) lets the browser
 * catch typos before hitting the server. The QR embeds the unguessable
 * `qr_token`, never the code, so codes can't be enumerated.
 */
class VerificationCode
{
    /** No ambiguous characters (0/O, 1/I removed). */
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function make(string $prefix, int $sequence, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $core = sprintf('TD-%s-%d-%s', strtoupper($prefix), $year, $seq);

        return $core.self::checkChar($core);
    }

    /** Mod-N checksum over the alphanumerics of the core string. */
    public static function checkChar(string $core): string
    {
        $n = strlen(self::ALPHABET);
        $sum = 0;
        $chars = preg_replace('/[^0-9A-Z]/', '', strtoupper($core));
        foreach (str_split($chars) as $i => $ch) {
            $val = ctype_digit($ch) ? (int) $ch : (ord($ch) - 55); // A=10 … Z=35
            $sum += $val * ($i % 2 === 0 ? 1 : 3);
        }

        return self::ALPHABET[$sum % $n];
    }

    /** Validate a full code's check character (client mirror of checkChar). */
    public static function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        if (strlen($code) < 2) {
            return false;
        }
        $core = substr($code, 0, -1);
        $check = substr($code, -1);

        return self::checkChar($core) === $check;
    }

    /** Unguessable token embedded in the QR URL. */
    public static function qrToken(): string
    {
        return Str::lower(Str::random(48));
    }
}
