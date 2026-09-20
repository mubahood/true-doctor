<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What plan prices are stored in
    |--------------------------------------------------------------------------
    |
    | Every row in `plans` is written in this currency. It is the platform's
    | own money — the currency the subscription is actually settled in — and
    | it is deliberately separate from a hospital's own billing currency,
    | which is a per-tenant setting and has nothing to do with what the
    | hospital pays us.
    |
    */

    'base_currency' => env('PRICING_BASE_CURRENCY', 'UGX'),

    /*
    |--------------------------------------------------------------------------
    | Shillings to the dollar
    |--------------------------------------------------------------------------
    |
    | Not here. The rate lives in `services.pesapal.usd_to_ugx_rate` and is
    | read through App\Support\PlatformCurrency::rate(), because the
    | subscription checkout charges against it. A second copy in this file is
    | how a plan came to be quoted at one price on the pricing page and
    | charged at another on the screen where somebody pays for it.
    |
    | Set it with USD_TO_UGX_RATE.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Where the shilling price is the one to show
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2. The East African Community, plus the neighbours a
    | Kampala-based service quotes in shillings anyway. Anybody outside this
    | list is quoted in US dollars.
    |
    */

    'east_africa' => [
        'UG', // Uganda
        'KE', // Kenya
        'TZ', // Tanzania
        'RW', // Rwanda
        'BI', // Burundi
        'SS', // South Sudan
        'CD', // DR Congo (EAC member since 2022)
        'SO', // Somalia (EAC member since 2024)
    ],

    /*
    |--------------------------------------------------------------------------
    | How the visitor's country is worked out
    |--------------------------------------------------------------------------
    */

    'geo' => [

        /*
         * Headers a CDN or load balancer sets from the client IP, in order of
         * preference. This is the cheapest and most accurate signal there is:
         * the edge has already done the IP lookup, and it costs us nothing.
         * Cloudflare sets CF-IPCountry once "IP Geolocation" is switched on
         * in the dashboard.
         *
         * Only trusted when the request came through a trusted proxy — see
         * VisitorRegion. A header is client-settable, and anybody can claim
         * to be anywhere.
         */
        'headers' => [
            'CF-IPCountry',           // Cloudflare
            'CloudFront-Viewer-Country', // AWS CloudFront
            'X-Vercel-IP-Country',    // Vercel
            'X-Country-Code',         // generic / nginx GeoIP module
            'X-AppEngine-Country',    // Google App Engine
        ],

        /*
         * A last-resort lookup of the client IP against a third-party
         * service, for hosts with no CDN and no GeoIP database.
         *
         * OFF by default, and deliberately so: switching it on sends every
         * visitor's IP address to somebody else's server. That is a decision
         * for whoever runs the site to make knowingly, not a default. When it
         * is on, the answer is cached per IP so a returning visitor costs
         * nothing, the timeout is short, and any failure falls through to the
         * next signal rather than delaying the page.
         *
         * Turn on with PRICING_GEO_LOOKUP=true.
         */
        'lookup' => [
            'enabled' => (bool) env('PRICING_GEO_LOOKUP', false),
            'endpoint' => env('PRICING_GEO_ENDPOINT', 'https://ipapi.co/{ip}/country/'),
            'timeout' => (float) env('PRICING_GEO_TIMEOUT', 1.5),
            'cache_days' => (int) env('PRICING_GEO_CACHE_DAYS', 30),
        ],

        /*
         * When nothing can tell us where somebody is. Dollars, because the
         * shilling price is the local exception and the dollar price is the
         * one that makes sense to a reader anywhere.
         */
        'fallback_currency' => env('PRICING_FALLBACK_CURRENCY', 'USD'),
    ],

];
