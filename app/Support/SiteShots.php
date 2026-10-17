<?php

namespace App\Support;

/**
 * The real product screenshots on the public site (scripts/site-shots.mjs).
 *
 * Read from the manifest the script writes, so every <img> carries its true
 * width and height (no layout shift) and a phone is offered the 800-wide copy.
 * A shot missing from the manifest renders nothing rather than a broken image.
 */
final class SiteShots
{
    /** @var array<string, array{file:string,w:int,h:int,kb:int,small?:array{file:string,w:int,h:int,kb:int}}>|null */
    private static ?array $manifest = null;

    /** @return array{file:string,w:int,h:int,kb:int,small?:array{file:string,w:int,h:int,kb:int}}|null */
    public static function get(string $name): ?array
    {
        return self::manifest()[$name] ?? null;
    }

    public static function url(string $file): string
    {
        return asset('images/site/shots/'.$file);
    }

    /** @return array<string, array{file:string,w:int,h:int,kb:int,small?:array{file:string,w:int,h:int,kb:int}}> */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $path = public_path('images/site/shots/manifest.json');
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$manifest = is_array($data) ? array_filter($data, fn ($s) => is_array($s) && isset($s['file'], $s['w'], $s['h'])) : [];
        }

        return self::$manifest;
    }
}
