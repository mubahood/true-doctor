<?php

namespace App\Support\Errors;

use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * The short reference an error page shows and the logs, the error list and
 * support all use: one per error, the same wherever it is asked for.
 */
final class ErrorRef
{
    /** @var WeakMap<Throwable,string>|null */
    private static ?WeakMap $refs = null;

    public static function for(Throwable $e): string
    {
        self::$refs ??= new WeakMap;

        if (! isset(self::$refs[$e])) {
            self::$refs[$e] = strtoupper(Str::random(6));
            if (app()->bound('request')) {
                request()->attributes->set('error_ref', self::$refs[$e]);
            }
        }

        return self::$refs[$e];
    }
}
