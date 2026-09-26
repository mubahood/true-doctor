<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An app too old for this server is told so, before it does anything.
 *
 * The phone and desktop app send `X-App-Version`; below
 * `services.mobile.min_version` the answer is 426 with where to get the new
 * one, and the app shows its update screen. Requests without the header —
 * the web, Field Mode, an integration — are not an app and pass.
 */
class EnsureAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $version = trim((string) $request->header('X-App-Version', ''));
        $minimum = (string) config('services.mobile.min_version', '1.0.0');

        if ($version !== '' && preg_match('/^\d+(\.\d+){0,2}$/', $version) && version_compare($version, $minimum, '<')) {
            return ApiResponse::error(
                ApiErrorCode::UpgradeRequired,
                'This version of the app is too old for the server. Update it to carry on — nothing on this device is lost.',
                426,
                null,
                ['minimum' => $minimum, 'download_url' => config('services.mobile.download_url')],
            );
        }

        return $next($request);
    }
}
