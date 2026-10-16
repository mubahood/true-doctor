<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translated messages for the API: the language the app asks for.
 *
 * Order: `Accept-Language` (first supported tag, by q-value), then the signed-in
 * user's own `locale` if the account has one, then whatever the app default is.
 * The web's `?lang=` / session choice (SetLocale) runs before this and is
 * overridden only by an explicit header, because an API client has no session.
 *
 * Only languages the server has strings for are honoured, so a header asking for
 * `de` falls through to the default instead of producing missing-key text. The
 * answer carries `Content-Language` so a client can tell what it actually got.
 */
class ApplyApiLocale
{
    /** The languages the server can answer in; `capabilities.locales` advertises the same list. */
    public const SUPPORTED = ['en', 'lg', 'sw'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromHeader((string) $request->header('Accept-Language', ''))
            ?? $this->fromUser($request);

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        $response = $next($request);

        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', app()->getLocale());
        }

        return $response;
    }

    private function fromHeader(string $header): ?string
    {
        if (trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $quality = 1.0;

            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $quality = (float) $m[1];
                }
            }

            if ($tag === '' || $tag === '*' || $quality <= 0) {
                continue;
            }

            $candidates[] = [$quality, $position, explode('-', $tag)[0]];
        }

        usort($candidates, fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        foreach ($candidates as [, , $language]) {
            if (in_array($language, self::SUPPORTED, true)) {
                return $language;
            }
        }

        return null;
    }

    private function fromUser(Request $request): ?string
    {
        // The token user, resolved early: cheap, and cached for the request.
        $locale = $request->user('sanctum')?->getAttribute('locale');

        return is_string($locale) && in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
