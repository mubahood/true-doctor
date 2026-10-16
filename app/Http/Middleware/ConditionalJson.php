<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for the JSON API.
 *
 * A successful GET (or HEAD) answer gets a weak ETag, `W/"<sha1 of the body>"`,
 * and `Cache-Control: private, no-cache` (a copy may be kept but must be
 * revalidated, and a shared cache must never keep it: the body is one user's,
 * one hospital's). If the request's `If-None-Match` names that tag the answer is
 * `304` with no body, so a phone on a poor link pays for headers only.
 *
 * Two details that matter:
 *
 * - The hash ignores values that change on every request and mean nothing to a
 *   cached copy (`server_time`, the clock in `meta`). Without that, the tag would
 *   differ every second and a 304 could never happen. The server's clock travels
 *   in the `X-Server-Time` header instead, on both the 200 and the 304, so a
 *   client keeping its device clock honest never needs the body for it.
 * - Only JSON 200s are tagged. PDFs, downloads, errors and every non-GET pass
 *   through untouched, so a write is never answered 304.
 *
 * The tag is computed from the final body, so permissions and tenancy are
 * already baked in: two users who see different data get different tags, and a
 * tag from hospital A can never validate hospital B's answer.
 */
class ConditionalJson
{
    /** Keys whose value is per-request noise, not content. */
    private const VOLATILE = ['server_time'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return $response;
        }

        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        $body = (string) $response->getContent();
        $etag = 'W/"'.sha1($this->stable($body)).'"';

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set('X-Server-Time', now()->toIso8601String());
        $response->headers->set('Vary', 'Authorization, Accept-Language');

        if ($this->matches((string) $request->headers->get('If-None-Match', ''), $etag)) {
            $notModified = response('', 304);
            foreach (['ETag', 'Cache-Control', 'X-Server-Time', 'Vary', 'Content-Language'] as $header) {
                if ($response->headers->has($header)) {
                    $notModified->headers->set($header, (string) $response->headers->get($header));
                }
            }

            return $notModified;
        }

        return $response;
    }

    /** The body with per-request noise removed, as a canonical string. */
    private function stable(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $strip = function (array $node) use (&$strip): array {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, self::VOLATILE, true)) {
                    unset($node[$key]);
                } elseif (is_array($value)) {
                    $node[$key] = $strip($value);
                }
            }

            return $node;
        };

        return (string) json_encode($strip($decoded));
    }

    /** Weak comparison, as RFC 9110 requires for If-None-Match; `*` matches anything. */
    private function matches(string $header, string $etag): bool
    {
        if (trim($header) === '') {
            return false;
        }

        if (trim($header) === '*') {
            return true;
        }

        $normalise = fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '';
        $ours = $normalise($etag);

        foreach (explode(',', $header) as $candidate) {
            if ($normalise($candidate) === $ours) {
                return true;
            }
        }

        return false;
    }
}
