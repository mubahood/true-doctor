<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Thin, cache-backed accessor over the key/value `settings` table.
 *
 * Safe to call before the table exists (fresh install / early boot): every
 * lookup falls back to the supplied default. Categories, regulatory bodies and
 * validity periods live in their own tables — this holds site-wide config only
 * (name, tagline, contacts, languages, verify rate-limit, QR base URL).
 */
class Settings
{
    private const CACHE_KEY = 'app.settings';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            return \App\Models\Setting::query()->pluck('value', 'key')->toArray();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
