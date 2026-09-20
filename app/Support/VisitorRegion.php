<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Where the person reading the public site appears to be, and therefore which
 * currency to quote them in.
 *
 * Shillings inside East Africa, dollars outside it. The question is only ever
 * asked on marketing pages — a hospital's own billing currency is a tenant
 * setting and has nothing to do with this.
 *
 * FOUR SIGNALS, IN ORDER, AND WHAT EACH IS WORTH:
 *
 *  1. What they chose. A switcher on the page, remembered in a cookie. It
 *     beats everything else, because a guess that somebody has corrected is
 *     not a guess any more.
 *  2. A country header from the CDN — Cloudflare's `CF-IPCountry` and the
 *     like. This IS the IP lookup: the edge did it, from the real client
 *     address, before the request reached us. Accurate and free. Only trusted
 *     when the request arrived through a trusted proxy, because a header is
 *     client-settable and anybody can claim to be anywhere.
 *  3. A lookup of the client IP against a third-party service. Genuinely
 *     IP-based and works on a plain host with no CDN, but it sends visitor IP
 *     addresses to somebody else, so it is OFF until switched on.
 *  4. The regional subtag of Accept-Language — `en-UG`, `sw-KE`. A real
 *     signal and a weak one: it says what a browser is set to, not where it
 *     is. Last, and only because it is better than nothing.
 *
 * When all four are silent the answer is dollars. There is no fifth guess,
 * and the switcher is always on the page.
 */
class VisitorRegion
{
    public const COOKIE = 'td_currency';

    /** How long a chosen currency is remembered, in minutes. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    private ?string $resolved = null;

    private bool $didResolve = false;

    public function __construct(private readonly Request $request) {}

    // ── What to quote in ─────────────────────────────────────────────────

    /** 'UGX' or 'USD'. */
    public function currency(): string
    {
        $chosen = $this->chosenCurrency();

        if ($chosen !== null) {
            return $chosen;
        }

        $country = $this->country();

        if ($country === null) {
            return $this->fallbackCurrency();
        }

        return in_array($country, $this->eastAfrica(), true)
            ? (string) config('pricing.base_currency', 'UGX')
            : 'USD';
    }

    public function isEastAfrican(): bool
    {
        return $this->currency() === config('pricing.base_currency', 'UGX');
    }

    /** True when the visitor picked a currency rather than being guessed at. */
    public function wasChosen(): bool
    {
        return $this->chosenCurrency() !== null;
    }

    /**
     * The currency this visitor explicitly asked for, if any.
     *
     * Validated against the two we support rather than trusted: this arrives
     * in a cookie, and a cookie is whatever the client says it is.
     */
    public function chosenCurrency(): ?string
    {
        $value = Str::upper((string) $this->request->cookie(self::COOKIE));

        return in_array($value, $this->supported(), true) ? $value : null;
    }

    /** @return list<string> */
    public function supported(): array
    {
        return [(string) config('pricing.base_currency', 'UGX'), 'USD'];
    }

    // ── Where they are ───────────────────────────────────────────────────

    /** ISO 3166-1 alpha-2, upper case, or null when nothing can say. */
    public function country(): ?string
    {
        if ($this->didResolve) {
            return $this->resolved;
        }

        $this->didResolve = true;

        $this->resolved = $this->fromHeader()
            ?? $this->fromLookup()
            ?? $this->fromLanguage();

        return $this->resolved;
    }

    /**
     * A country the CDN worked out from the real client IP.
     *
     * Trusted only behind a trusted proxy. Without that check this would be
     * a header anybody could set to move themselves to another continent —
     * which for a price is exactly the thing not to allow.
     */
    private function fromHeader(): ?string
    {
        if (! $this->behindTrustedProxy()) {
            return null;
        }

        foreach ((array) config('pricing.geo.headers', []) as $header) {
            $value = $this->normalise($this->request->header($header));

            // Cloudflare sends XX for "could not tell" and T1 for Tor.
            if ($value !== null && ! in_array($value, ['XX', 'T1'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The client IP, looked up against a third-party service.
     *
     * Off unless switched on. Cached per address, short timeout, and any
     * failure at all returns null so the page never waits on somebody else's
     * uptime to render a price.
     */
    private function fromLookup(): ?string
    {
        if (! config('pricing.geo.lookup.enabled')) {
            return null;
        }

        $ip = (string) $this->request->ip();

        if ($ip === '' || ! $this->isPublicAddress($ip)) {
            return null;
        }

        $days = max(1, (int) config('pricing.geo.lookup.cache_days', 30));

        $cached = Cache::remember(
            'geo:'.sha1($ip),
            now()->addDays($days),
            function () use ($ip): string {
                $endpoint = str_replace('{ip}', urlencode($ip), (string) config('pricing.geo.lookup.endpoint'));

                try {
                    $response = Http::timeout((float) config('pricing.geo.lookup.timeout', 1.5))
                        ->retry(1, 0)
                        ->get($endpoint);
                } catch (\Throwable) {
                    // Cached as a miss too, so a service that is down is not
                    // re-tried on every page view by every visitor.
                    return '';
                }

                if (! $response->successful()) {
                    return '';
                }

                return (string) ($this->normalise($response->body()) ?? '');
            },
        );

        return $cached === '' ? null : $cached;
    }

    /**
     * The region in something like `en-UG,en;q=0.9`.
     *
     * A weak signal, used last. A browser set to en-UG is very likely in
     * Uganda; a browser set to plain `en` says nothing, and is ignored rather
     * than guessed at.
     */
    private function fromLanguage(): ?string
    {
        $header = (string) $this->request->header('Accept-Language', '');

        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = trim(Str::before($part, ';'));

            // language-REGION, e.g. en-UG or sw-KE. A bare `en` has no region.
            if (preg_match('/^[a-z]{2,3}-([A-Za-z]{2})$/', $tag, $matches) === 1) {
                return Str::upper($matches[1]);
            }
        }

        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Whether this request reached us through a proxy we configured.
     *
     * Laravel has already applied TrustProxies by the time a controller
     * runs, so a forwarded request is one whose client IP was rewritten from
     * the forwarding headers. `isFromTrustedProxy()` is Symfony's own answer
     * to the same question.
     */
    private function behindTrustedProxy(): bool
    {
        return $this->request->isFromTrustedProxy();
    }

    /** Loopback, private and reserved ranges cannot be in any country. */
    private function isPublicAddress(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function normalise(?string $value): ?string
    {
        $value = Str::upper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    /** @return list<string> */
    private function eastAfrica(): array
    {
        return array_map(
            fn ($code) => Str::upper((string) $code),
            (array) config('pricing.east_africa', []),
        );
    }

    private function fallbackCurrency(): string
    {
        $fallback = Str::upper((string) config('pricing.geo.fallback_currency', 'USD'));

        return in_array($fallback, $this->supported(), true) ? $fallback : 'USD';
    }

    /** The cookie that remembers a visitor's choice. */
    public static function cookieFor(string $currency): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            name: self::COOKIE,
            value: $currency,
            minutes: self::COOKIE_MINUTES,
            secure: null,
            httpOnly: false,
            sameSite: 'lax',
        );
    }
}
