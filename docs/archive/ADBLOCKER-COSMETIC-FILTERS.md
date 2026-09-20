# When an ad blocker "eats" your app — diagnosis & permanent fix

> Symptom: the site loads (200, no JS errors), but with an ad blocker on (uBlock Origin,
> AdGuard, Brave Shields, Ghostery, Pi-hole+cosmetic, etc.) large chunks — or the **whole page
> body** — are blank/hidden. Turn the blocker off and everything is fine.

This is almost never a "your code is broken" problem. It's a **naming collision** with ad-blocker
filter lists. This guide explains exactly why, how to confirm it, and how to fix and prevent every
variant.

---

## 1. What is actually happening

Ad blockers work in two layers:

1. **Network filters** — block *requests* whose URL matches a pattern
   (`||doubleclick.net^`, `/ads.js`, `/analytics`, `*/ad/*`). Result: a script/image/iframe never loads.
2. **Cosmetic filters** — inject a stylesheet into every page that does
   `display: none !important` on *elements matching a CSS selector* from the filter lists
   (EasyList, EasyPrivacy, uBlock filters, etc.). Result: elements that exist in your DOM get
   **hidden**, even though your CSS/JS is perfectly fine.

The cosmetic list contains thousands of generic selectors for hiding ad slots, e.g.:

```
.ad-wrapper        .ad-container     .ad-content     .ad-slot      .ad-banner
.ad-box            .ad-header        .ad-footer      .ad-sidebar   .ads
#ad                 #ads              [id^="ad-"]     [class^="ad_"]  .sponsored
.banner-ad         .adsbox           .social-share   .newsletter-popup ...
```

If your markup uses any of those exact class names / IDs, the blocker hides them. If the hidden
element is a **top-level container** (a shell `<div class="ad-wrapper">` that wraps the whole app),
the entire page appears blank.

> Real case in this project: the admin back-office shell used the `ad-` prefix
> (`ad-wrapper`, `ad-content`, `ad-sidebar`, `ad-card`, …). `.ad-wrapper` and `.ad-content` are in
> EasyList → the blocker set `display:none` on the whole admin shell → blank page. The public site
> (classes `.wrap`, `.card`, `.page`) was never affected. Fix was renaming the prefix `ad-` → `tb-`.

---

## 2. Confirm it's cosmetic filtering (2-minute triage)

Do these in order:

1. **Toggle the blocker off / open in a private window with no extensions.** If it renders → it's
   the blocker, not your code. (Also try a different browser.)
2. **DevTools → Elements:** find the missing container. If it's in the DOM but invisible, inspect
   **Computed styles**. A blocker-hidden element shows `display: none` coming from an
   **injected/user stylesheet** (not your file) — in Chrome it appears as `display: none` with the
   source being an extension, or the element has an injected attribute. uBlock also adds a
   `##selector` you can see in its logger.
3. **uBlock Origin → "Logger" (the list icon):** reload the page; it shows every network block and
   every cosmetic (`##`) rule applied to the page, naming the exact selector. This tells you which
   class/ID/URL triggered it.
4. **Network tab:** if a *script/image* is `(blocked:other)` / `net::ERR_BLOCKED_BY_CLIENT`, that's
   a **network filter** (see §4).

If DevTools shows your CSS is correct but something outside your CSS forces `display:none`, or the
Logger prints a `##.something` rule → cosmetic filter. Proceed to §3.

---

## 3. Fixing cosmetic-filter hits (class/ID names)

**Principle: never name UI elements after advertising.** Rename the offending classes/IDs to a
neutral, product-specific prefix.

### Trigger names to avoid in `class`/`id`
- Prefixes/words: `ad`, `ads`, `ad-`, `ad_`, `adv`, `advert`, `banner`, `sponsor`, `sponsored`,
  `promo`, `popup`, `newsletter`, `social-share`, `share-bar`, `cookie`(sometimes), `interstitial`,
  `outbrain`, `taboola`, `dfp`, `gpt`, `doubleclick`.
- Especially dangerous as **layout containers**: `ad-wrapper`, `ad-container`, `ad-content`,
  `ad-sidebar`, `ad-header`, `ad-footer`, `ad-box`, `ad-slot`, `ads`, `#ad`, `#ads`.

### Pick a safe prefix
Use something tied to your product: `tb-` (true-doctor back-office), `app-`, `ui-`, `x-`,
`tdx-`, `c-`/`l-` (ITCSS), or a BEM block name that isn't ad-ish. Any of these are invisible to
filter lists.

### Do the rename safely (this is the part people get wrong)
A blind find-replace of `ad-` corrupts real words and CSS. Watch for:
- Words containing the substring: `re**ad-**only`, `lo**ad-**more`, `he**ad-**er`, `--b**ad-**soft`
  (a `--bad-soft` CSS var!), `down**load-**`, `thre**ad-**`.
- CSS custom properties: `--ad-muted` must be renamed in **both** its definition and every `var()`.
- Compound classes: `btn-ad`, `badge-ad`, `card-ad` — handle these first.

A robust approach (what was used here): only rewrite tokens that are **real class boundaries**, i.e.
`ad-` immediately preceded by `.` (CSS selector), a space, or a quote (`"`/`'`) in HTML.

```perl
# rename.pl — run:  perl -i rename.pl file1 file2 ...
while (<>) {
  s/btn-ad/btn-tb/g;          # compound classes first
  s/badge-ad/badge-tb/g;
  s/--ad-/--tb-/g;            # CSS custom properties (def + usage)
  s/(?<=[.\s"'(>])ad-/tb-/g;  # only real class tokens: .ad-, " ad-, "ad-, (ad-
  print;
}
```

```bash
# apply across the design system + all templates
perl -i rename.pl public/css/app.css $(find resources/views/admin -name '*.blade.php')
# verify: zero blockable tokens, and words/vars untouched
grep -rhoE '(\.|"| )ad-[a-z]+' public/css resources/views/admin   # expect 0
grep -c '\-\-bad-soft' public/css/app.css                          # still intact
```

Then rebuild any compiled templates/CSS caches (`php artisan view:clear && view:cache`, or your
bundler) and hard-reload with the blocker ON to confirm.

> Note: you cannot beat cosmetic filters with more CSS. Their rule is
> `selector { display:none !important; }` injected at document level; you can't out-specificity a
> matching `!important` on the same element reliably. **Renaming is the only durable fix.**

---

## 4. Fixing network-filter hits (blocked requests)

If a **request** is blocked (`ERR_BLOCKED_BY_CLIENT`), the URL matched a network rule. Common causes
and fixes:

| Blocked thing | Why | Fix |
|---|---|---|
| `js/ads.js`, `analytics.js`, `tracking.js`, `/pixel`, `/collect` | filename/path matches ad/tracker patterns | rename the file/route (`ads.js` → `promotions.js` is still risky — use `feed.js`, `metrics.js` only if self-hosted & not a known tracker path) |
| Third-party widget (Tawk.to, Intercom, GA, Facebook Pixel, Hotjar) | on tracker lists | expected; make the site work without it (don't let a blocked widget break layout), and load it defensively |
| Your own image `banner.jpg`, `ad-hero.png` | matches `banner`/`ad` | rename asset |
| `/api/track`, `/events`, `/beacon` endpoints | look like telemetry | rename route, or accept that some clients block it and never depend on it for core UX |

Key rule: **core functionality must never depend on a request that a blocker might drop.** Analytics,
chat, and ads are enhancements — wrap them so a blocked load is a no-op, not a crash.

---

## 5. Prevent it — make it impossible to reintroduce

1. **Adopt a namespaced class convention** for the whole app (BEM block names, or a prefix like
   `c-`, `l-`, `tb-`). Ban `ad`, `ads`, `banner`, `sponsor`, `promo`, `popup` as standalone
   class/ID tokens in code review.
2. **Lint / CI guard.** Add a grep check that fails the build if forbidden tokens appear in markup:
   ```bash
   # fails build if any blockable class token is present
   if grep -rEn '(class|id)="[^"]*\b(ad|ads|ad-[a-z]|banner|sponsor|promo)\b' resources/views; then
     echo "Ad-blocker-unsafe class/id name found"; exit 1
   fi
   ```
3. **Test with a blocker in QA.** Keep one browser profile with uBlock Origin + EasyList enabled and
   smoke-test key pages there before release. This bug is invisible in a clean browser.
4. **Self-host assets** where practical; give them boring, functional names (`app.js`, `main.css`,
   `logo.png`) — never `ads`, `banner`, `sponsor`, `track`, `analytics`.
5. **Don't put critical UI inside anything named like an ad slot**, and never make a top-level layout
   wrapper use a risky name (blast radius = the whole page).

---

## 6. Quick reference — the fix in one paragraph

Your app isn't broken; an ad blocker's **cosmetic filter** is hiding DOM elements whose
`class`/`id` matches ad-slot patterns (classic offenders: `ad-wrapper`, `ad-container`,
`ad-content`, `ads`, `#ad`). Confirm with the blocker off / uBlock Logger, then **rename those
classes to a neutral prefix** (carefully, so you don't corrupt words like `read-`, `download-`, or
CSS vars like `--bad-soft`), rebuild template/CSS caches, and add a CI grep + a blocker-enabled QA
pass so it never returns. For blocked *requests* (scripts/images/endpoints), rename the file/path and
make sure no core feature depends on a request a blocker might drop.
