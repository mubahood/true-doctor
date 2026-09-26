<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\TrafficEvent;
use App\Models\TrafficSession;
use App\Support\LandingIntent;
use App\Support\VisitorRegion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writing down who arrived, from where, what they looked at, and what the
 * advertisement that sent them was.
 *
 * THE RULE THIS SERVICE IS BUILT AROUND: recording a visit must never be able
 * to break the visit. A public page that 500s because an analytics insert
 * failed is a page that costs money on a running ad campaign, so every public
 * method here is wrapped and a failure is swallowed after being logged.
 * Nothing this class does is allowed to reach the visitor.
 *
 * WHAT IS NOT STORED. No raw IP address — a keyed hash, so a returning
 * visitor can be recognised without the address being recoverable from the
 * table or a backup of it. Nothing anybody typed. Everything kept is either
 * something the ad put in the URL or something the browser volunteered.
 */
class TrafficRecorder
{
    /** The visitor cookie. Its value is never stored — only a keyed hash of it. */
    public const COOKIE = 'td_v';

    /** How long a visitor is the same visitor. The campaign spec asks for 30 days. */
    private const COOKIE_DAYS = 30;

    /** Away this long and coming back is a new visit by the same person. */
    private const VISIT_GAP_MINUTES = 30;

    /** Request attribute holding the id minted for a first-time visitor. */
    private const MINTED = 'traffic.visitor_id';

    /** Paths that are not a person reading a page. */
    private const IGNORED_PREFIXES = [
        'admin', 'super', 'api', 'livewire', 'build', 'storage',
        'gateway', 'sitemap.xml', 'robots.txt', 'up', 'field',
    ];

    /**
     * Substrings that mean a crawler.
     *
     * Recorded rather than dropped: a session flagged is a session somebody
     * can count and exclude. Dropped, it is a number nobody can check. Most
     * crawlers say "bot"; the rest are the ones that do not.
     */
    private const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'headless', 'phantom', 'lighthouse', 'pingdom', 'uptime', 'monitor',
        'facebookexternalhit', 'preview', 'scanner', 'probe', 'postman', 'insomnia',
        'mediapartners-google', 'inspectiontool', 'feedfetcher', 'apis-google',
        'go-http-client', 'okhttp', 'axios', 'node-fetch', 'guzzle', 'java/',
        'whatsapp', 'telegram',
    ];

    /** Pages whose first opening is a step towards signing up. */
    private const MILESTONES = [
        '/pricing' => 'viewed_pricing_at',
        '/register' => 'opened_signup_at',
        '/test-login' => 'opened_demo_at',
    ];

    /**
     * Record one hit on a public page, after it has been answered.
     *
     * After, not before: the status code, the redirect target and the time
     * the server took are the three facts that say whether a landing URL is
     * healthy, and none of them exist until the response does.
     *
     * Returns null when there is nothing to record — a crawler is still
     * recorded, but a request for an asset is not a page view.
     */
    public function record(Request $request, LandingIntent $intent, ?Response $response = null, ?int $durationMs = null): ?TrafficSession
    {
        try {
            if (! $this->isPageView($request)) {
                return null;
            }

            $now = Carbon::now();
            $path = $this->pathOf($request);
            $kind = $response?->isRedirection() ? 'redirect' : 'view';
            $status = $response?->getStatusCode();

            // A redirect is not a page somebody read, and neither is an error
            // page. Counting either doubles every sitelink or flatters a
            // broken one.
            $read = $kind === 'view' && ($status === null || $status < 400);

            $session = $this->session($request, $intent, $now);
            $fresh = $this->isFreshClick($session, $path, $intent, $now);
            [$clickId, $clickType] = $intent->clickId();

            if ($read) {
                $session->page_views = (int) $session->page_views + 1;

                if (($milestone = self::MILESTONES[$path] ?? null) !== null && $session->{$milestone} === null) {
                    $session->{$milestone} = $now;
                }
            }

            if ($fresh) {
                $session->clicks = (int) $session->clicks + 1;
                $session->last_click_at = $now;
            }

            $session->save();

            TrafficEvent::create([
                'traffic_session_id' => $session->id,
                'kind' => $kind,
                'path' => $path,
                'status' => $status,
                'redirect_to' => $kind === 'redirect' ? $this->location($response) : null,
                'duration_ms' => $durationMs === null ? null : max(0, min($durationMs, 3_600_000)),
                'section' => $intent->section,
                'module' => $intent->module,
                'plan' => $intent->plan,
                'is_click' => $fresh,
                'utm_source' => $intent->utm['utm_source'] ?? null,
                'utm_medium' => $intent->utm['utm_medium'] ?? null,
                'utm_campaign' => $intent->utm['utm_campaign'] ?? null,
                'utm_content' => $intent->utm['utm_content'] ?? null,
                'keyword' => $intent->keyword(),
                'click_id' => $clickId,
                'click_id_type' => $clickType,
                'ad_params' => $intent->adParams === [] ? null : $intent->adParams,
                'referrer_host' => $intent->referrerHost,
                'created_at' => $now,
            ]);

            return $session;
        } catch (\Throwable $e) {
            // A failure here must never reach the visitor. A public page that
            // 500s because an analytics insert failed costs money on a live
            // ad campaign.
            report($e);

            return null;
        }
    }

    /**
     * Something a visitor DID rather than opened — an enquiry sent.
     *
     * Written onto the trail of the visit it belongs to, and never creates a
     * visit of its own: a form posted by something that never loaded a page
     * is not a visitor worth a row.
     */
    public function action(Request $request, string $kind): void
    {
        try {
            $session = $this->sessionFor($request);

            if ($session === null) {
                return;
            }

            $now = Carbon::now();

            if ($kind === 'enquiry' && $session->enquired_at === null) {
                $session->enquired_at = $now;
            }

            $session->last_seen_at = $now;
            $session->save();

            TrafficEvent::create([
                'traffic_session_id' => $session->id,
                'kind' => $kind,
                'path' => $this->pathOf($request),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Attach a hospital to the visit that brought it.
     *
     * Called when somebody signs up. Writes the attribution onto the hospital
     * as well as marking the session, so the answer survives the traffic
     * tables being pruned and a hospital's own record says where it came
     * from.
     *
     * Where the answer comes from, in order: the visit row (first touch,
     * thirty days), then what the Laravel session put aside when they
     * arrived (for a browser that refused the cookie). The POST itself
     * carries nothing — it is a form, not an ad click.
     */
    public function convert(Hospital $hospital, Request $request): void
    {
        try {
            $session = $this->sessionFor($request);

            $stored = $request->hasSession() ? $request->session()->get('attribution', []) : [];
            $fallback = LandingIntent::fromStored(is_array($stored) ? $stored : []);

            $attributes = array_filter($fallback->toAttributes(), fn ($v) => $v !== null);

            if ($session !== null) {
                // The first touch wins, because the advertisement that
                // introduced somebody is the one that earned the sign-up.
                $attributes = array_filter([
                    'utm_source' => $session->utm_source,
                    'utm_medium' => $session->utm_medium,
                    'utm_campaign' => $session->utm_campaign,
                    'utm_content' => $session->utm_content,
                    'utm_term' => $session->utm_term,
                    'gclid' => $session->gclid,
                    'gbraid' => $session->gbraid,
                    'wbraid' => $session->wbraid,
                    'landing_section' => $session->landing_section,
                    'landing_module' => $session->landing_module,
                    'landing_plan' => $session->landing_plan,
                    'landing_referrer' => $session->referrer_host,
                ], fn ($v) => $v !== null) + $attributes;
            }

            $now = Carbon::now();

            if ($attributes !== []) {
                $hospital->forceFill($attributes + ['attributed_at' => $now])->save();
            }

            if ($session === null) {
                return;
            }

            $session->forceFill([
                'converted_hospital_id' => $hospital->id,
                'converted_at' => $now,
                'last_seen_at' => $now,
            ])->save();

            TrafficEvent::create([
                'traffic_session_id' => $session->id,
                'kind' => 'signup',
                'path' => $this->pathOf($request),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Losing the attribution is a reporting problem. Losing the
            // sign-up because of it would be a business one.
            report($e);
        }
    }

    /** The visit this request belongs to, if it has one. */
    public function sessionFor(Request $request): ?TrafficSession
    {
        try {
            return TrafficSession::firstWhere('visitor_key', $this->visitorKey($request));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Remember this visitor for thirty days.
     *
     * Carries exactly the id this request was recorded under — see
     * visitorId() for why that matters. Not set for a crawler: it would not
     * send it back, and one that did would split into two rows.
     */
    public function cookie(Request $request): ?\Symfony\Component\HttpFoundation\Cookie
    {
        if ($this->incomingCookie($request) !== null || $this->looksLikeABot((string) $request->userAgent())) {
            return null;
        }

        return Cookie::make(
            name: self::COOKIE,
            value: $this->visitorId($request),
            minutes: self::COOKIE_DAYS * 24 * 60,
            secure: null,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    // ── The parts ────────────────────────────────────────────────────────

    /** The visitor's session, found by cookie or created. Not yet saved. */
    private function session(Request $request, LandingIntent $intent, Carbon $now): TrafficSession
    {
        $key = $this->visitorKey($request);
        $session = TrafficSession::firstWhere('visitor_key', $key);

        if ($session === null) {
            $agent = (string) $request->userAgent();

            try {
                $session = TrafficSession::create([
                    'uuid' => (string) Str::uuid(),
                    'visitor_key' => $key,
                    'landing_path' => $this->pathOf($request),
                    'referrer_host' => $intent->referrerHost,
                    'device' => $this->device($agent),
                    'browser' => $this->browser($agent),
                    'platform' => $this->platform($agent),
                    'country' => $this->country($request),
                    'user_agent' => mb_substr($agent, 0, 255),
                    'ip_hash' => $this->ipHash($request),
                    'is_bot' => $this->looksLikeABot($agent),
                    'ad_params' => $intent->adParams === [] ? null : $intent->adParams,
                    'page_views' => 0,
                    'visits' => 1,
                    'clicks' => 0,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ] + $intent->toAttributes());
            } catch (UniqueConstraintViolationException) {
                // Two first requests from one visitor at once (a prefetch and
                // the real load). The other one won; use its row.
                $session = TrafficSession::where('visitor_key', $key)->firstOrFail();
            }

            return $session;
        }

        if ($intent->hasAttribution() && ! $session->hasAttribution()) {
            // A visitor who arrived directly and LATER clicked an ad: the
            // first touch was nothing, so the ad is the first thing that
            // actually introduced them and it may claim the session.
            $session->fill(array_filter($intent->toAttributes(), fn ($v) => $v !== null));
            $session->ad_params ??= $intent->adParams === [] ? null : $intent->adParams;
        }

        if ($session->last_seen_at !== null && $session->last_seen_at->lt($now->copy()->subMinutes(self::VISIT_GAP_MINUTES))) {
            $session->visits = (int) $session->visits + 1;
        }

        $session->last_seen_at = $now;

        return $session;
    }

    /**
     * Whether this hit is a click the campaign paid for, rather than the same
     * click seen again.
     *
     * Three ways the same click comes back, none of which Google bills
     * twice and none of which this may count twice:
     *
     *   - our own redirect: `/?section=pricing&gclid=X` → `/pricing?gclid=X`
     *     arrives as two hits carrying the same tags;
     *   - a reload, or the back button, on a URL with a click id in it;
     *   - a reload of a URL tagged with UTMs but no click id.
     */
    private function isFreshClick(TrafficSession $session, string $path, LandingIntent $intent, Carbon $now): bool
    {
        if (! $intent->hasAttribution()) {
            return false;
        }

        if ($session->wasRecentlyCreated) {
            return true;
        }

        $last = $session->events()->latest('id')->first();

        if ($last !== null
            && $last->kind === 'redirect'
            && $last->created_at?->gte($now->copy()->subMinute())
            && strtok((string) $last->redirect_to, '#?') === $path) {
            return false;
        }

        [$clickId] = $intent->clickId();

        if ($clickId !== null) {
            return ! $session->events()->where('click_id', $clickId)->exists();
        }

        return ! $session->events()
            ->where('is_click', true)
            ->where('path', $path)
            ->where('utm_campaign', $intent->utm['utm_campaign'] ?? null)
            ->where('utm_content', $intent->utm['utm_content'] ?? null)
            ->where('created_at', '>=', $now->copy()->subMinutes(self::VISIT_GAP_MINUTES))
            ->exists();
    }

    /**
     * A stable id for this visitor.
     *
     * The cookie when there is one. A first-time visitor has none YET, and
     * the id their cookie is about to carry is minted here, once per
     * request, and used for the key straight away — keying the first hit any
     * other way files the ad click under one row and every page after it,
     * sign-up included, under another, which loses the attribution in
     * exactly the case it exists for.
     *
     * A crawler gets a hash of its address and user agent instead: it will
     * not keep a cookie, and this stitches its hits into one row rather than
     * one row per hit.
     */
    private function visitorKey(Request $request): string
    {
        $raw = $this->incomingCookie($request) === null && $this->looksLikeABot((string) $request->userAgent())
            ? 'a:'.$request->ip().'|'.$request->userAgent()
            : 'c:'.$this->visitorId($request);

        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }

    private function visitorId(Request $request): string
    {
        if (($cookie = $this->incomingCookie($request)) !== null) {
            return $cookie;
        }

        if (! is_string($minted = $request->attributes->get(self::MINTED))) {
            $minted = (string) Str::uuid();
            $request->attributes->set(self::MINTED, $minted);
        }

        return $minted;
    }

    private function incomingCookie(Request $request): ?string
    {
        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) && $cookie !== '' && strlen($cookie) <= 64 ? $cookie : null;
    }

    /** Keyed, so the address cannot be recovered from the table. */
    private function ipHash(Request $request): ?string
    {
        $ip = (string) $request->ip();

        return $ip === '' ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function pathOf(Request $request): string
    {
        return mb_substr('/'.ltrim($request->path(), '/'), 0, 191);
    }

    /** Where a redirect sent them: path and fragment, never the query string. */
    private function location(?Response $response): ?string
    {
        $location = $response?->headers->get('Location');

        if (! is_string($location) || $location === '') {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH) ?: '/';
        $fragment = parse_url($location, PHP_URL_FRAGMENT);

        return mb_substr($path.(is_string($fragment) && $fragment !== '' ? '#'.$fragment : ''), 0, 191);
    }

    private function isPageView(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        $path = ltrim($request->path(), '/');

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        // Anything with an extension is an asset, not a page.
        return ! preg_match('/\.[a-z0-9]{2,5}$/i', $path);
    }

    private function looksLikeABot(string $agent): bool
    {
        if ($agent === '') {
            return true;
        }

        $agent = Str::lower($agent);

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($agent, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function device(string $agent): string
    {
        $agent = Str::lower($agent);

        return match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    private function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Brave') => 'Brave',
            str_contains($agent, 'SamsungBrowser') => 'Samsung',
            str_contains($agent, 'Firefox') || str_contains($agent, 'FxiOS') => 'Firefox',
            // Chrome must be tested after the browsers that also claim it,
            // and Safari after Chrome for the same reason.
            str_contains($agent, 'Chrome') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Other',
        };
    }

    private function platform(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Other',
        };
    }

    /** The same signals the pricing pages use to pick a currency. */
    private function country(Request $request): ?string
    {
        try {
            return (new VisitorRegion($request))->country();
        } catch (\Throwable) {
            return null;
        }
    }
}
