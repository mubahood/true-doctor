<?php

namespace App\Support\Errors;

/**
 * Takes what could name a patient or open an account out of error text before
 * it is stored or sent anywhere: emails, phone numbers, long numbers, tokens,
 * quoted values (an SQL error quotes the row it choked on) and JSON values.
 * A name written in prose cannot be recognised, so the text is also cut short.
 */
final class Scrubber
{
    public const MAX = 500;

    public static function scrub(string $text, int $max = self::MAX): string
    {
        $patterns = [
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/' => '[email]',
            '/bearer\s+\S+/i' => 'Bearer [token]',
            '/\b(token|authorization|password|secret|api[_-]?key)\b(\s*[:=]\s*)("[^"]*"|\S+)/i' => '$1$2[hidden]',
            '/\b\d+\|[A-Za-z0-9]{16,}\b/' => '[token]',
            '/\b[A-Za-z0-9_\-]{32,}\b/' => '[token]',
            '/\+\d[\d\s().\-]{7,}\d/' => '[phone]',
            '/\b0\d{2,3}[\s.\-]?\d{3}[\s.\-]?\d{3,4}\b/' => '[phone]',
            '/\d{8,}/' => '[number]',
            "/'[^'\\n]{0,300}'/" => "'?'",
            '/(:\s*)"(?:[^"\\\\\n]|\\\\.){0,300}"/' => '$1"?"',
        ];

        $out = (string) preg_replace(array_keys($patterns), array_values($patterns), mb_substr($text, 0, 4000));

        return mb_strlen($out) > $max ? mb_substr($out, 0, $max).'…' : $out;
    }
}
