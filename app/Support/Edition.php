<?php

namespace App\Support;

final class Edition
{
    public const SAAS = 'saas';

    public const STANDALONE = 'standalone';

    public static function name(): string
    {
        $name = config('edition.name', self::SAAS);

        if (! in_array($name, [self::SAAS, self::STANDALONE], true)) {
            throw new \RuntimeException('APP_EDITION must be saas or standalone.');
        }

        return $name;
    }

    public static function isSaas(): bool
    {
        return self::name() === self::SAAS;
    }

    public static function isStandalone(): bool
    {
        return self::name() === self::STANDALONE;
    }

    public static function version(): string
    {
        $file = base_path('RELEASE.json');
        if (! is_file($file)) {
            return 'dev';
        }

        $manifest = json_decode((string) file_get_contents($file), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null)
            ? $manifest['version']
            : 'dev';
    }
}
