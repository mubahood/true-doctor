<?php

namespace App\Http\Middleware;

use App\Services\TrafficRecorder;
use App\Support\LandingIntent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every arrival on the public site: sent where the ad asked, then recorded.
 *
 * Runs on the public pages only.
 *
 *   1. SEND THEM ON, keeping the query string. If the UTMs and click ids
 *      were dropped in the redirect the destination would look like direct
 *      traffic and the attribution would be lost between one page and the
 *      next.
 *   2. RECORD THE HIT AS IT ARRIVED, once it has been answered. A click on
 *      "Pricing & Plans" that ends up on /pricing is a click on the
 *      sitelink: the hit on `/` is written as a redirect carrying the
 *      section the ad named, and the page it led to as a view of its own —
 *      and the recorder knows the second is the same click, not another.
 *      Recording after the response is what lets the trail carry the status
 *      code and the time the server took, which are how anybody finds out a
 *      landing URL has started failing.
 *
 * The `section` routing applies where an ad actually lands — the home page.
 * Everywhere else this only records, so `/pricing?section=contact` does not
 * bounce somebody who is already reading.
 */
class HandleLandingTraffic
{
    public function __construct(private readonly TrafficRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $intent = LandingIntent::fromRequest($request);

        $response = $this->isLanding($request) && ($route = $intent->route()) !== null
            ? $this->sendOn($route, $intent)
            : $next($request);

        // Belt as well as braces. TrafficRecorder catches its own failures,
        // but this middleware sits in front of every public page and must
        // not depend on that staying true — a page that 500s because an
        // analytics insert failed costs money on a live ad campaign.
        try {
            $this->recorder->record($request, $intent, $response, intdiv(hrtime(true) - $started, 1_000_000));

            // A fallback for the sign-up form, for a browser that refuses the
            // visitor cookie. First touch only, like the visit row: a later
            // click does not rewrite who introduced them.
            if ($intent->hasAttribution() && $request->hasSession() && ! $request->session()->has('attribution')) {
                $request->session()->put('attribution', $intent->toAttributes());
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Separately, so a failed insert still leaves the visitor recognisable
        // on the next page.
        try {
            if (($cookie = $this->recorder->cookie($request)) !== null) {
                $response->headers->setCookie($cookie);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // A parameterised URL must not be indexed as a page of its own. The
        // canonical tag in the layout already points at the clean address;
        // this says the same thing to a crawler that never renders the head.
        if ($request->query() !== [] && ! $response->isRedirection()) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow', false);
        }

        return $response;
    }

    /**
     * Only the front door routes by `section`.
     *
     * Every ad in the campaign lands on `/`, and somebody already reading
     * /pricing should not be thrown to /contact because a parameter came
     * along for the ride in a shared link.
     */
    private function isLanding(Request $request): bool
    {
        return $request->path() === '/' || $request->path() === '';
    }

    /**
     * 302, not 301.
     *
     * A permanent redirect is cached by the browser, so the NEXT ad click on
     * the same parameters would never reach the server at all — and would
     * never be recorded. The campaign report would quietly stop counting.
     */
    private function sendOn(string $route, LandingIntent $intent): Response
    {
        $url = route($route, $intent->forwardQuery());

        if (($fragment = $intent->fragment()) !== null) {
            $url .= '#'.$fragment;
        }

        return redirect()->to($url, 302);
    }
}
