# The public site

Seven pages at the root of the application — home, product, pricing, security,
contact, privacy, terms — plus `sitemap.xml`. They share `layouts.marketing`
and `resources/css/marketing.css`, and they are outside the SPA: no Livewire,
no Alpine, and every behaviour is an enhancement that the page works without.

## What a signed-in visitor sees

The two sign-up buttons become one "Go to dashboard". `/admin/login`,
`/register` and `/test-login` carry `guest`, so somebody already signed in is
sent to their dashboard rather than shown a form they have no use for.

`$demo` and `$region` reach every page through a view composer in
`AppServiceProvider`, **not** through a `@php` block in the layout: a child
view's sections are buffered before the layout runs, so anything the layout
defines is undefined inside the page that extends it.

## Which currency a visitor is quoted in

Plan prices are stored in shillings — that is what the subscription is settled
in. A reader outside East Africa is quoted in dollars at a fixed rate from
`config/services.php` (`USD_TO_UGX_RATE`, default **3,800 shillings to the
dollar**). A fixed rate, not a live one: a price that moves while somebody is
reading it is worse than one that is a few percent stale, and a public page has
no business calling a rates API on every render.

`App\Support\VisitorRegion` decides, from four signals in order:

| # | Signal | Worth | On by default |
|---|---|---|---|
| 1 | A currency the visitor chose (cookie, one year) | Decisive — a corrected guess is not a guess | yes |
| 2 | A country header from the CDN (`CF-IPCountry` and friends) | **This is the IP lookup** — the edge did it from the real client address | yes |
| 3 | A lookup of the client IP against a third-party service | Genuinely IP-based, works with no CDN | **no** |
| 4 | The region subtag of `Accept-Language` (`en-UG`, `sw-KE`) | Real but weak — says what a browser is set to, not where it is | yes |

Nothing resolves → dollars, and the switcher is on every page.

**Signal 2 is only trusted behind a trusted proxy.** A header is client-settable;
without that check a visitor could move themselves to another continent to
change the price. Set `app.trusted_proxies` for it to take effect.

**Signal 3 is off.** Switching it on sends every visitor's IP address to a third
party, which is a decision for whoever runs the site, not a default. Turn it on
with `PRICING_GEO_LOOKUP=true`; answers are cached per address for 30 days, the
timeout is short, and any failure falls through rather than delaying the page.

### On a plain host with no CDN

Only signals 1 and 4 fire. Most visitors will be quoted in dollars — which is
the intended default for anybody outside East Africa — and a Ugandan visitor
whose browser is set to `en-UG` will see shillings. For real IP detection on
such a host, either put the site behind Cloudflare with IP Geolocation on
(free, signal 2) or set `PRICING_GEO_LOOKUP=true` (signal 3).

## The hero pattern

`.hero::before` tiles a 120px clinical motif as an inline `data:` URI — no
request, and it cannot 404 — radially masked so it fades before the section
edge. `.hero::after` lays a soft white radial over the middle so the headline
never competes with the texture behind it. `PublicSiteTest` asserts all three
against the **built** stylesheet, because a rule that only exists in the source
is a rule nobody is served.

## The contact form

`POST /contact` → `MarketingController::enquire` → a mail notification to
`config('mail.enquiries_to')` (falls back to the from-address, so the form is
never silently a black hole). Three things stand between it and a spam cannon,
none of which asks a real visitor to prove anything: a rate limit of five per
IP per hour, a honeypot field positioned off-screen rather than hidden, and
server-side validation. The reply-to is the address given, and the mail says
plainly that the address is unverified.

## A trap worth knowing about

Blade extracts `@php ... @endphp` blocks with `/(?<!@)@php(.*?)@endphp/s`
**before** it compiles anything else. The match is non-greedy but it pairs the
first `@php` it finds with the first `@endphp` after it — so a `@php(...)`
one-liner anywhere above a `@php ... @endphp` block makes that regex swallow
everything in between, emit an unterminated `<?php`, and silently stop
compiling the rest of the file. The symptom is "syntax error, unexpected end of
file" pointing at the last line of the view.

This bit the pricing page once. The fix, and the rule: **arrays belong in the
controller**, not in a `@php` block in a view.

## Guides, and "Help" on every screen

`/guides` and `/guides/{slug}` (`GuideController`, SaaS only) render the guides for clinic owners from `config/guides.php`: the first six of the twelve in roadmap 02 §7. Each is dated, signed, lists its sources and ends with one product link; the words carry no em dash (a test reads the file). They use the privacy page's article layout (`.page`), so they add no CSS, and appear in the sitemap and the footer.

In the back office, `App\Support\HelpGuides::ROUTES` maps each screen (route name or prefix) to the guide that answers its question. The page header shows "Help" and the topbar's help menu "Guide for this screen", both from that one mapping; a screen without a guide links to `/guides`. On a hospital's own copy the links go to the guides online (`GUIDES_URL`, default the update server). To add a guide: add it to `config/guides.php`, then point screens at it in `ROUTES`.
