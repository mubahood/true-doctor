<?php

namespace Tests\Unit;

use Illuminate\Cache\RateLimiter as RateLimiterRegistry;
use Tests\TestCase;

/**
 * HMS_PLAN.md §3.C — the legacy audit's rate-limit and CORS middleware were
 * 0-byte/unregistered. Confirms the 'api' limiter is actually registered
 * (Laravel's slimmed skeleton leaves the `api` group unthrottled unless
 * asked — see bootstrap/app.php's throttleApi()) and CORS defaults closed.
 */
class ApiHardeningConfigTest extends TestCase
{
    public function test_the_api_rate_limiter_is_registered(): void
    {
        $limiter = app(RateLimiterRegistry::class)->limiter('api');

        $this->assertNotNull($limiter, "The 'api' named rate limiter must be registered.");
    }

    public function test_cors_allows_no_origins_by_default(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));
    }

    public function test_cors_does_not_support_credentials_by_default(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
    }
}
