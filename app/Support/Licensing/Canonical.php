<?php

namespace App\Support\Licensing;

final class Canonical
{
    /** A single stable encoding for detached signatures on licence payloads. */
    public static function json(array $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }

        return $value;
    }
}
