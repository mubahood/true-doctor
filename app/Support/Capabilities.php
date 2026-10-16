<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Middleware\ApplyApiLocale;
use App\Models\Hospital;
use App\Models\User;
use App\Support\Licensing\InstalledLicence;
use App\Support\Practice\Practice;

/**
 * What THIS server, for THIS user, can do: the block an app reads to decide
 * what to draw and what to attempt (`meta.capabilities`, `bootstrap.capabilities`).
 *
 * The app degrades when a key is absent (an older server), so a feature is only
 * ever switched on here once the endpoint behind it exists and works on both
 * editions. Keys other workstreams own (`agenda`, `push`, `attachments`,
 * `batch`) are `false` until they flip them.
 *
 * Permissions are the same strings the Policies check - the user's Spatie
 * permissions - so a button the app hides is a button the server would refuse.
 */
final class Capabilities
{
    /** The oldest API contract this server still speaks. */
    public const MIN_API_VERSION = 1;

    /** `capabilities.limits.per_page_max`; the cap every list applies. */
    public const PER_PAGE_MAX = 100;

    /** Requests a minute per user (the `api` limiter in AppServiceProvider). */
    public const RATE_PER_MIN = 240;

    /** Minutes of inactivity after which the app signs the user out. */
    public const IDLE_TIMEOUT_MINUTES = 15;

    /** @return array<string,mixed> */
    public static function for(User $user, ?Hospital $hospital): array
    {
        $practice = Practice::of($hospital);

        return [
            'api_version' => MetaController::API_VERSION,
            'min_api_version' => self::MIN_API_VERSION,
            'deprecated_after' => null,
            'edition' => Edition::name(),
            'practice_kind' => $practice->sector->key,
            'modules' => self::modules($practice),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'features' => self::features(),
            'limits' => [
                'per_page_max' => self::PER_PAGE_MAX,
                'upload_max_kb' => self::uploadMaxKb(),
                'rate_per_min' => self::RATE_PER_MIN,
                'idle_timeout_minutes' => self::IDLE_TIMEOUT_MINUTES,
            ],
            'locales' => ApplyApiLocale::SUPPORTED,
            'server_time' => now()->toIso8601String(),
            'timezone' => $hospital?->timezone ?: config('app.timezone'),
            'licence' => Edition::isStandalone() ? self::licence() : null,
        ];
    }

    /**
     * Features the server advertises. Flip a key to true in the PR that ships
     * the endpoint behind it, not before.
     *
     * @return array<string,bool>
     */
    public static function features(): array
    {
        return [
            'search' => true,       // GET /search
            'etag' => true,         // ConditionalJson on GET
            'tombstones' => true,   // `removed` in pull and bootstrap
            'bootstrap' => true,    // GET /bootstrap
            'agenda' => true,       // GET /me/agenda (and /me/next-actions)
            // Real push needs a driver other than the logging `null` one and
            // its credentials; a standalone copy never has ours.
            'push' => config('push.driver', 'null') !== 'null',
            'attachments' => false, // W3-E
            'batch' => false,
        ];
    }

    /**
     * The effective upload ceiling in KB: the smaller of PHP's `post_max_size`
     * and `upload_max_filesize` (a request is refused at whichever is lower).
     */
    public static function uploadMaxKb(): int
    {
        $limits = array_filter([
            self::bytes((string) ini_get('post_max_size')),
            self::bytes((string) ini_get('upload_max_filesize')),
        ], fn (int $b) => $b > 0);

        // 0 / -1 mean "no limit" to PHP; report a sane, honest ceiling.
        return $limits === [] ? 2048 * 1024 : intdiv(min($limits), 1024);
    }

    /** `8M`, `2G`, `512K` or plain bytes, as bytes. */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $m)) {
            return 0;
        }

        $n = max(0, (int) $m[1]);

        return match (strtolower($m[2])) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** @return array<string,bool> every switchable module and whether this practice uses it */
    private static function modules(Practice $practice): array
    {
        $out = [];

        foreach (array_keys(Practice::MODULES) as $module) {
            $out[$module] = $practice->uses($module);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function licence(): array
    {
        $licence = app(InstalledLicence::class);
        $until = $licence->updatesUntil();

        return [
            'valid' => $licence->isValid(),
            'id' => $licence->id(),
            'customer' => $licence->customer(),
            'updates_until' => $until?->toDateString(),
            'updates_active' => $until === null ? null : ! $until->isPast(),
            'limits' => array_filter([
                'max_staff' => $licence->limit('max_staff'),
                'max_patients' => $licence->limit('max_patients'),
                'max_beds' => $licence->limit('max_beds'),
            ], fn ($v) => $v !== null),
        ];
    }
}
