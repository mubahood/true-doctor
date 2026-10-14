# Proposal: the Standalone edition (one-off licence, self-hosted)

> **To the developer agent picking this up.** Everything from §1 onwards is a
> proposal: the design, the names, the tables, the order of work. It was written
> with the codebase open, and every file, class and constraint named here is
> real as of commit `7de5199` (October 2026). Change any of it if you find a
> better way, and record why in `docs/decisions.md`.
>
> **The rules in the next section are not proposals.** They are what the owner
> must get, whatever design you choose. If your design breaks one, change the
> design, not the rule. If a rule seems impossible, stop and ask the owner
> before building around it.

---

## Non-negotiable rules

These come directly from the owner's brief. Each rule has a check. The work is
not finished until every check passes.

### What the owner must get

**R1 — One codebase. No branches, no other folders.**
The standalone (one-off) product is this repository's `main`, configured
differently. There is no edition branch, no fork, no copied project
(`true-doctor-one-off` or similar), and no second repository.
*Check:* one repository and one `main`; the edition is chosen by configuration
(`APP_EDITION`) at install time; a fix merged to `main` reaches both the SaaS
and the next standalone release with no extra step.

**R2 — Everything else stays the same.**
A standalone install has every feature the SaaS has: all clinical modules,
billing, finance, pharmacy, lab, imaging, inpatient, kinds of practice,
offline devices, the API and the mobile app. The only differences are around
the hospital: no public site, sign-up, trials, subscriptions or super-admin
panel; one organisation; installed on the buyer's server.
*Check:* the full test suite passes with `APP_EDITION=saas` and with
`APP_EDITION=standalone`. Only tests for SaaS-only pages differ, and those
assert the page is absent.

**R3 — One enterprise per installation.**
A standalone install serves exactly one organisation. Nothing — the
interface, the API, a command or code — can create a second one.
*Check:* a test tries every path to a second hospital, and every one is
refused.

**R4 — It runs on the buyer's own server, set up by a guided procedure.**
The buyer installs it on their own hosting, including ordinary cPanel shared
hosting, by following an installer and a guide. They need no developer and
no command line.
*Check:* someone non-technical takes a fresh shared-hosting account from an
uploaded zip to a working sign-in using only the installer and the guide.

**R5 — The super admin stages a version from the admin interface, simply.**
A release (the zip) is made from the super-admin panel: one form, one button,
visible progress, then publish. No terminal, no laptop, no manual zipping,
no developer.
*Check:* a super admin stages, builds and publishes a release using only the
browser.

**R6 — The zip contains everything.**
One file holds the application code, its dependencies (`vendor/`), its built
assets, **all migrations, all seeders**, the installer and the guide. The
buyer downloads nothing else and runs no Composer or npm.
*Check:* extracting the zip on a server with only PHP and MySQL, then running
the installer, gives a working system.

**R7 — A buyer starts on the latest.**
A fresh install from a release lands on that release's latest version: every
migration up to that release, the latest reference data (roles and
permissions, districts), the latest specialty packs and the latest chart of
accounts. Nobody installs an old version and then upgrades.
*Check:* a release is publishable only after the build has proven an install
from an empty database succeeds. The portal always offers the newest release
the buyer's licence covers.

**R8 — The public site sells it, and payment gives access at once.**
A buyer chooses the one-off product on the public site and pays online. As
soon as the payment is confirmed, they get access to download it and their
licence — automatically, with nobody at our end doing anything.
*Check:* a sandbox purchase ends in a working download link without human
action.

**R9 — The guide is where the download is.**
Wherever the buyer downloads, the step-by-step installation guide is right
there. The same guide is inside the zip.
*Check:* the download page links the guide; `INSTALL.md` is in every zip.

**R10 — Managed from the admin interface, dynamically.**
Releases, one-off products and their prices, and licences (including ones
sold offline) are all managed from the super-admin panel. Selling a new
version or changing a price never needs a code change or a deploy.
*Check:* the owner publishes a new release and changes a price from the
browser, and the public site reflects both immediately.

**R11 — Simple to use.**
Every screen here follows the existing house style (`docs/livewire-conventions.md`)
and asks only what a person can answer. Defaults are pre-filled — the next
version number, the release notes from the commits — so the usual case is
reviewing and pressing one button.
*Check:* staging a release needs no more than confirming the pre-filled form.

### Safety floor (also non-negotiable)

**S1 — No secret ever ships.** No `.env`, credentials, keys, `ssh.txt`,
database dumps or `storage/` contents in a release. Enforce it with an
allow-list, and have the build fail if a forbidden file appears.

**S2 — The SaaS never breaks along the way.** Every phase leaves `main`
deployable, and true-doctor.online keeps working, with the full suite green,
before and after each merge.

**S3 — Patient care is never blocked by licensing.** An expired, missing or
mismatched licence may limit updates and show a warning. It must never stop
anyone opening a patient, a visit or an invoice.

---

## Contents

0. [Non-negotiable rules](#non-negotiable-rules)
1. [What we are building](#1-what-we-are-building)
2. [Read this first](#2-read-this-first)
3. [The idea in one picture](#3-the-idea-in-one-picture)
4. [Part A — The edition switch](#4-part-a--the-edition-switch)
5. [Part B — Licences](#5-part-b--licences)
6. [Part C — Releases: staging a version from the super-admin panel](#6-part-c--releases-staging-a-version-from-the-super-admin-panel)
7. [Part D — Selling it on the public site](#7-part-d--selling-it-on-the-public-site)
8. [Part E — Installing on the buyer's server](#8-part-e--installing-on-the-buyers-server)
9. [Part F — Updating an installed copy](#9-part-f--updating-an-installed-copy)
10. [Part G — Everything else the standalone edition must get right](#10-part-g--everything-else-the-standalone-edition-must-get-right)
11. [Data model](#11-data-model)
12. [Delivery plan](#12-delivery-plan)
13. [Testing](#13-testing)
14. [Risks and how each is handled](#14-risks-and-how-each-is-handled)
15. [Questions for the owner](#15-questions-for-the-owner)

---

## 1. What we are building

True-Doctor is sold today as a service: a hospital signs up on
true-doctor.online, gets a 14-day trial (`RegistrationService::TRIAL_DAYS`) and
pays monthly or yearly through Pesapal. Many hospitals all live in one
database, separated by `hospital_id`.

Some buyers want to **own** a copy instead: pay once, run it on their own
server, have their data on their own premises, and serve one organisation.
That is the **Standalone edition**. It is sold as a **one-off licence**.

| | SaaS edition (today) | Standalone edition (new) |
|---|---|---|
| Who runs the server | Us | The buyer |
| Hospitals per install | Many | Exactly one |
| How they pay | Subscription (monthly/yearly) | Once, for a licence; updates for a period |
| How it starts | Self sign-up and trial | Download, then the installer |
| Super-admin panel | Yes (us) | No — there is nobody "above" the hospital |
| Public marketing site | Yes | No — `/` is the sign-in page |
| Clinical, billing, finance, pharmacy, lab, inpatient, offline, API, mobile app | Yes | **Identical** |

**Goals**

- One codebase; the edition is a runtime setting.
- A super admin stages a release (a signed zip) in a couple of clicks.
- A buyer pays on the public site, receives a licence, downloads the latest
  release and installs it by following a guided installer — without needing a
  developer.
- An installed copy upgrades to later releases safely, with a backup and a
  rollback, and without ever losing data.
- A fresh install starts on the latest schema and the latest reference data
  (roles, permissions, districts, the specialty packs, the chart of accounts).

**Non-goals (for now)**

- Running several hospitals on one standalone install. Buyers who need that
  are SaaS customers.
- Obfuscating or encrypting the PHP source (see §14 for why).
- Docker or Kubernetes packaging. Most buyers in this market use cPanel-style
  shared hosting or a single VPS. A `docker-compose.yml` can come later and
  reuse everything here.

---

## 2. Read this first

Before writing code, read these. They hold the house rules this work must
follow.

| Doc | Why it matters here |
|---|---|
| `docs/livewire-conventions.md` | Every new screen (releases, licences, installer, updates) follows these: `wire:navigate`, `x-ui.*` kit, thin components, rules in FormRequests |
| `docs/tenancy.md` | How `hospital_id`, `CurrentHospital` and `HospitalScope` isolate tenants — the standalone edition keeps all of it |
| `docs/onboarding.md`, `docs/practice.md` | The installer ends by handing over to the existing setup wizard |
| `docs/finance.md` | `finance:backfill` must run after every update |
| `docs/deployment-cpanel.md`, `deploy/deploy.sh` | The closest thing we have to an installer and an updater today. Shared-hosting facts that apply equally to buyers: no Node, no Redis, database queue with a cron-driven worker |
| `docs/navigation.md` | How menu entries are gated — the edition becomes one more gate |
| `docs/subscriptions.md`, `docs/payments-gateway.md` | The Pesapal flow that licence purchases reuse |
| `docs/testing.md` | Gates are switched off in `phpunit.xml` and switched on per test — the edition follows the same pattern |

Facts about the live host (`true-doctor.online`, HostGator shared, checked
read-only): PHP 8.3 with `zip`, `sodium`, `bcmath`, `gd`, `intl`, `pdo_mysql`,
`fileinfo`; `git`, `zip`, `unzip`, `mysqldump` on the path; CLI
`max_execution_time` 0, `memory_limit` 512M; the production `vendor/` is
122 MB. Builds can run there.

---

## 3. The idea in one picture

```
                         ONE REPOSITORY (main)
                                  │
               deploy.sh pulls it │
                                  ▼
   ┌──────────────────────── true-doctor.online ────────────────────────┐
   │  APP_EDITION=saas                                                  │
   │                                                                    │
   │  Super admin ─► Releases ─► "Stage v1.6.0" ─► build job            │
   │                                   │  git archive + vendor + build  │
   │                                   │  + manifest + sha256 + Ed25519 │
   │                                   ▼                                │
   │                          storage/app/private/releases/1.6.0.zip    │
   │                                   │                                │
   │  Public site ─► "Own it" ─► Pesapal ─► Licence issued ─► email     │
   │                                                │                   │
   │  Customer portal (magic link) ◄────────────────┘                   │
   │    • download latest release   • licence file   • install guide    │
   └────────────────────────────────────────────────────────────────────┘
                                  │ zip + licence.json
                                  ▼
   ┌──────────────────────── buyer's server ────────────────────────────┐
   │  APP_EDITION=standalone (written by the installer)                 │
   │  /install ─► requirements ─► licence ─► database ─► migrate+seed   │
   │           ─► hospital + owner ─► mail ─► cron ─► setup wizard      │
   │                                                                    │
   │  Later: Settings ─► System & updates ─► upload or fetch v1.7.0     │
   │         verify signature ─► backup ─► swap ─► migrate ─► check     │
   └────────────────────────────────────────────────────────────────────┘
```

The single most important design decision: **a standalone install is the SaaS
application with exactly one hospital.** All `hospital_id` columns, the global
scope and the tenancy tests stay exactly as they are. That is what keeps one
codebase honest: nothing clinical or financial has an "if standalone" in it.

---

## 4. Part A — The edition switch

### 4.1 Configuration

New file `config/edition.php`:

```php
<?php

return [
    // saas | standalone. Written by the installer; never changed afterwards.
    'name' => env('APP_EDITION', 'saas'),

    // Standalone only: the licence file the installer saved.
    'licence_path' => env('APP_LICENCE_PATH', storage_path('app/private/licence.json')),

    // Standalone only: where to look for updates. Null = manual upload only
    // (a clinic with no reliable internet).
    'update_server' => env('APP_UPDATE_SERVER', 'https://true-doctor.online'),

    // SaaS only: the Ed25519 secret key that signs releases and licences.
    // NEVER in the repository, NEVER in a release zip (see §6.4).
    'signing_secret' => env('RELEASE_SIGNING_SECRET'),
];
```

### 4.2 One place that knows the edition

`app/Support/Edition.php`, in the style of `App\Support\Practice\Practice`:

```php
<?php

namespace App\Support;

/**
 * Which product this install is (docs/proposals/standalone-edition.md).
 *
 * The SaaS edition hosts many hospitals and sells subscriptions; the
 * standalone edition is the same application on a buyer's own server, with
 * exactly one hospital and a licence instead of a subscription. Everything
 * clinical and financial is identical — this class only ever decides what
 * SURROUNDS the hospital: sign-up, plans, the super-admin panel, the public site.
 */
final class Edition
{
    public const SAAS = 'saas';
    public const STANDALONE = 'standalone';

    public static function name(): string
    {
        return config('edition.name') === self::STANDALONE ? self::STANDALONE : self::SAAS;
    }

    public static function isSaas(): bool
    {
        return self::name() === self::SAAS;
    }

    public static function isStandalone(): bool
    {
        return self::name() === self::STANDALONE;
    }

    /** The release this code is, from the RELEASE.json a build writes; "dev" in a checkout. */
    public static function version(): string
    {
        static $version = null;

        return $version ??= (function () {
            $manifest = base_path('RELEASE.json');
            $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

            return is_array($data) && isset($data['version']) ? (string) $data['version'] : 'dev';
        })();
    }
}
```

Add `<env name="APP_EDITION" value="saas"/>` to `phpunit.xml` beside
`ONBOARDING_GATE`, so the default suite runs as SaaS and standalone behaviour is
switched on per test (§13).

### 4.3 A middleware for routes that belong to one edition

`app/Http/Middleware/RequireEdition.php`, registered as `edition` in
`bootstrap/app.php` beside `onboarding`, `subscribed` and `super`:

```php
public function handle(Request $request, Closure $next, string $edition): Response
{
    // Not a 403: in the other edition the page does not exist at all.
    abort_unless(Edition::name() === $edition, 404);

    return $next($request);
}
```

### 4.4 Every SaaS surface, and what happens to it

This table is the work list for Part A. Every row is a real place in the code.

| Surface | Where it is | Standalone behaviour | How |
|---|---|---|---|
| Public marketing site (`/`, `/features`, `/pricing`, `/security`, `/contact`, `/privacy`, `/terms`, sitemap) | `routes/web.php` "Public — marketing" group, `MarketingController`, `resources/views/marketing/*` | Gone. `/` redirects to `admin.login` (or the dashboard when signed in) | Wrap the group in `edition:saas`; add a standalone `/` route |
| Self sign-up | `/register` routes, `RegistrationController`, `RegistrationService` | Gone. The installer creates the only hospital and its owner | `edition:saas` on both `/register` routes |
| Trials and subscription gate | `EnsureSubscribed`, `SubscriptionState`, `config('tenancy.enforce_subscription')` | Off. A licence is checked differently (§5) | First line of `EnsureSubscribed::handle`: `if (Edition::isStandalone()) return $next($request);` |
| Subscription page and Pesapal checkout | `admin.subscription.*`, `Livewire\Subscription\Index`, `SubscriptionCheckoutController`, `gateway/pesapal/*` | Replaced by **Settings → Licence & updates** (§9) | `edition:saas` on those routes; new page for standalone |
| Plan limits | `App\Support\PlanLimit` (`max_staff`, `max_patients`, `max_beds`) | Limits come from the licence, if it sets any; otherwise none | `PlanLimit::limitFor()` asks `Licence::current()->limit($key)` when standalone |
| Super-admin panel | `/super/*`, `Livewire\Super\*`, the `super` middleware, the "Platform" section of `Navigation::sections()` | Gone. There is no super admin on a standalone install | `edition:saas` on the `super` group; Navigation section gate `'super'` plus `'edition' => 'saas'` |
| "Site settings" | `admin.settings.index` (`Livewire\Settings\Site`), a super-admin page | Gone — its contents (site name, contacts) are the hospital's letterhead in standalone | Hide via Navigation; route `edition:saas` |
| Traffic and campaigns | `TrafficRecorder`, `landing` middleware, `super.traffic.*` | Off | `landing` middleware returns early when standalone |
| Trial reminders | `subscriptions:trial-reminders` in `routes/console.php` | Not scheduled | `->when(fn () => Edition::isSaas())` |
| Demo mode | `config/demo.php`, `ResetDemoHospital`, demo seeders | Off and refused | `ResetDemoHospital` refuses unless SaaS |
| Hospital purge | `hospitals:purge` (`PurgeHospitals`) | Refused — it would delete the only hospital | Refuse unless SaaS |
| Telescope and Horizon | `TelescopeServiceProvider`, `HorizonServiceProvider` | Not registered | Release `.env` keeps `TELESCOPE_ENABLED=false`; Horizon is unused on shared hosts anyway |
| API meta for the app | `Api\V1\MetaController` | Says `edition: standalone`, includes `version`, and omits `subscription` | Add `'edition' => Edition::name(), 'version' => Edition::version()` |

### 4.5 Exactly one hospital

The installer creates the only hospital. After that, nothing may create
another. Put the invariant on the model so no code path can get around it:

```php
// App\Models\Hospital::booted()
static::creating(function (self $hospital) {
    if (Edition::isStandalone() && static::withoutGlobalScopes()->withTrashed()->exists()) {
        throw new \DomainException('This installation is licensed for one organisation.');
    }
    // … existing uuid/slug defaults
});
```

`ResolveHospital` needs no change: every standalone user has a `hospital_id`.
There is no super admin, so no user ever has a null `hospital_id`.

### 4.6 The menu

`App\Support\Navigation` already filters entries by `can`, `gate`, `module`
(practice) and `tenant`. Add `edition`, read the same way:

```php
// In Navigation::for(), beside $inUse:
$inEdition = fn (array $it) => ! isset($it['edition']) || $it['edition'] === Edition::name();
```

Tag the Platform section `'edition' => 'saas'`, the "Subscription" entry
`'edition' => 'saas'`, and add a "Licence & updates" entry with
`'edition' => 'standalone'` under Settings. `SidebarMenuTest` walks every
entry for every role, so run it once per edition (§13).

---

## 5. Part B — Licences

### 5.1 What a licence is

A licence is a **signed JSON document**. The buyer's install verifies it
offline with a public key that ships in the code. No call home is needed to
*run*: clinics have unreliable internet, and a hospital system must never stop
seeing patients because a licence server is unreachable.

```json
{
  "licence": {
    "id": "TD-7K2M-9QXR-4HFA-2WZP",
    "customer": "St. Mary's Medical Centre Ltd",
    "organisation": "St. Mary's Medical Centre",
    "email": "admin@stmarys.example",
    "product": "standalone-hospital",
    "seats": null,
    "limits": {"max_staff": null},
    "domains": ["hms.stmarys.example"],
    "issued_at": "2026-11-02T09:14:00Z",
    "updates_until": "2027-11-02",
    "issuer": "true-doctor.online",
    "format": 1
  },
  "key_id": "2026-a",
  "signature": "base64(Ed25519 signature over the canonical JSON of `licence`)"
}
```

Canonical JSON: keys sorted recursively, `JSON_UNESCAPED_SLASHES |
JSON_UNESCAPED_UNICODE`, no whitespace. Write one helper, `Canonical::json()`,
and use it for both signing and verifying; the most common signature bug is
two slightly different serialisers.

### 5.2 Signing and verifying with libsodium

PHP has `sodium` built in, and the host has it. Ed25519 signatures are short,
fast, and cannot be forged with the public key.

```php
// SaaS side — App\Services\Licensing\Signer
public function sign(array $payload): array
{
    $secret = base64_decode((string) config('edition.signing_secret'), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new \RuntimeException('RELEASE_SIGNING_SECRET is not set to a valid Ed25519 secret key.');
    }

    return [
        'key_id' => self::CURRENT_KEY_ID,
        'signature' => base64_encode(sodium_crypto_sign_detached(Canonical::json($payload), $secret)),
    ];
}

// Both sides — App\Support\Licensing\Verifier
public function verifies(array $payload, string $signature, string $keyId): bool
{
    $public = self::PUBLIC_KEYS[$keyId] ?? null;   // base64 public keys, committed in the code

    return $public !== null && sodium_crypto_sign_verify_detached(
        base64_decode($signature, true) ?: '',
        Canonical::json($payload),
        base64_decode($public, true) ?: '',
    );
}
```

Generate the key pair once with a command that prints both halves and stores
neither: `php artisan licensing:keygen`. The public key goes into
`Verifier::PUBLIC_KEYS` (committed). The secret goes into the live `.env` as
`RELEASE_SIGNING_SECRET`, plus one offline backup. `key_id` makes key rotation
possible: add the new public key, start signing with the new id, keep
verifying the old one.

### 5.3 What a licence does, and does not, control

This is a deliberate product decision, and the recommended one:

| Situation | Behaviour |
|---|---|
| Valid licence | Normal |
| `updates_until` has passed | **The software keeps working forever.** Releases built after that date refuse to install, and the update page offers a renewal |
| Domain does not match | A banner for administrators ("This installation is licensed for hms.stmarys.example"). **Never a lockout** — hospitals move servers and rename hosts at 2 a.m. |
| Licence missing or tampered | Banner for administrators. Creating *new* hospitals is already impossible (§4.5). Clinical work is never blocked |
| `limits.max_staff` reached | Exactly what `PlanLimit` does today: refuse to add the next staff member, with the message the SaaS edition shows |

**A hospital system must never refuse to show a patient's record because of a
licence.** Enforcement lives at the edges: updates, staff seats, and the
honesty of a banner.

`App\Support\Licensing\Licence::current()` reads and verifies the file once per
request (memoised like `HospitalSettings`) and exposes `isValid()`,
`updatesUntil()`, `limit($key)`, `matchesHost($host)` and `customer()`.

---

## 6. Part C — Releases: staging a version from the super-admin panel

### 6.1 What the super admin sees

A new page in the Platform section: **Super → Releases**
(`super.releases.index`, `App\Livewire\Super\Releases\Index`). It's a list
page, following the conventions:

```
┌ Releases ───────────────────────────────────────────────── [ Stage a new version ] ┐
│ Version   Status      Built from         Size     Built               Downloads   │
│ 1.6.0     ● Published 7de5199 (main)     34.8 MB  2 Nov 2026 09:12    41          │
│ 1.5.2     ● Published a91c0de            34.1 MB  12 Oct 2026         12   [Yank] │
│ 1.6.1     ◌ Building  ── step 4 of 7: Packing (62%) ──                             │
└────────────────────────────────────────────────────────────────────────────────────┘
```

**Stage a new version** opens one modal, `<x-ui.modal size="lg">`:

- **Version**, prefilled with the next minor (`1.7.0`) and editable; validated
  as semver and greater than the last published version.
- **What's new**, prefilled with the commit subjects since the last release
  (`git log --format=%s <last>..HEAD`). The commit messages in this repository
  are written as plain sentences, so the prefill is already close to release
  notes. Editable Markdown.
- **Built from**, read-only: the commit currently deployed
  (`git rev-parse HEAD`), its subject and date. A warning appears if the
  working tree is dirty (`git status --porcelain` is not empty, ignoring the
  known untracked `error_log` and `.env.bak.*`).
- **Channel**: `stable` (sold) or `beta` (offered only to licences flagged for
  it).
- One button: **Build**. Nothing else to decide.

The row then shows live progress (`wire:poll.3s.visible`), and finally
**Publish** (makes it downloadable by entitled licences) or **Discard**.

### 6.2 "You release what is running"

The key simplification: **a release is always built from the commit currently
deployed on true-doctor.online.** That commit has already passed
`deploy.sh`, its `vendor/` was installed from its own `composer.lock`
(`--no-dev`), its `public/build` assets are committed, and real hospitals are
using it. The build therefore copies proven files instead of reconstructing
them:

- Code comes from `git archive <commit>`, which takes exactly the tracked
  files, so `.env`, `ssh.txt`, `storage/` contents and `error_log` cannot leak.
- `vendor/` is copied from the live application directory, after checking that
  `composer.lock` at the commit is byte-identical to the deployed one.
- No Composer and no Node run during a build.

If the owner later wants to release a commit that is *not* deployed, add a
"build in isolation" mode that runs `composer install --no-dev` into the
staging directory. It isn't needed for v1.

### 6.3 The build pipeline

Builds take longer than a web request and longer than the queue worker's
55-second slot (`routes/console.php` runs `queue:work --stop-when-empty
--max-time=55`). So a build is **not** a queued job. It is a row in `releases`
with `status = queued`, picked up by a scheduled command that runs as its own
CLI process (where `max_execution_time` is 0):

```php
// routes/console.php
Schedule::command('releases:build-pending')
    ->everyMinute()
    ->withoutOverlapping(30)       // a build holds the lock for up to 30 minutes
    ->runInBackground()
    ->when(fn () => \App\Support\Edition::isSaas());
```

`App\Services\Releases\ReleaseBuilder` runs these steps. Each one records its
progress on the row (`step`, `progress`, `build_log`), so the page can show it
and a failure says exactly where it happened:

| # | Step | What it does | Fails when |
|---|---|---|---|
| 1 | **Stage** | `mkdir storage/app/private/releases/build-<id>`; `git archive --format=tar <commit> \| tar -x -C <stage>` | Commit not found |
| 2 | **Dependencies** | Compare `composer.lock` hashes; copy `vendor/` (rsync or `File::copyDirectory`) | Lock differs from deployed |
| 3 | **Prune** | Remove what a buyer must not get or does not need (§6.4) | — |
| 4 | **Edition files** | Write `.env.example` for standalone (§8.4), `RELEASE.json` (§6.5), `INSTALL.md`, an empty `storage/` skeleton | — |
| 5 | **Prove it** | In the stage directory, with `APP_EDITION=standalone` and `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, run `php artisan migrate --force --seed --seeder=Database\\Seeders\\StandaloneReferenceSeeder` and `php artisan about`. A release whose migrations do not run from empty is never offered to anyone | Any non-zero exit |
| 6 | **Pack** | `ZipArchive` (deterministic: sorted paths, fixed mtimes) → `true-doctor-standalone-1.7.0.zip`; sha256 | Disk full |
| 7 | **Sign** | Ed25519 signature over `RELEASE.json` (which already lists the zip's file hashes) and over the zip's sha256; store both | No signing key |

Then the staging directory is deleted, and the row becomes `ready` with size,
sha256 and signature. On any failure the row becomes `failed` with the log,
and the stage is deleted. Retention: keep every published release, and delete
`failed` and `discarded` artefacts after seven days.

The deployed `vendor/` is 122 MB. Zipped, expect roughly 30–40 MB per release.
The host has 409 GB free, so storage is not a concern.

### 6.4 What goes in, and what never does

**In:** `app/`, `bootstrap/` (minus `cache/*.php`), `config/`, `database/`
(migrations, seeders, factories — factories are harmless and some seeders use
them), `lang/`, `public/` (including `public/build`), `resources/`, `routes/`,
`vendor/`, `artisan`, `composer.json`, `composer.lock`, `LICENSE`,
`CHANGELOG.md`, plus generated `RELEASE.json`, `.env.example`, `INSTALL.md`.

**Never in** (enforced by an allow-list, not a deny-list, so a new secret file
cannot slip through):

- `.env*` except the generated `.env.example`, `ssh.txt`, any `*.sql*`,
  `storage/` contents, `error_log`, `.git/`.
- `tests/`, `node_modules/`, `phpunit.xml`, `phpstan.neon`, `pint.json`,
  `scripts/`, `.github/`.
- `deploy/` — it is our hosting's script. The buyer gets the installer instead.
- `docs/` — internal engineering notes. The buyer gets `INSTALL.md` and the
  guide on the portal (§7.4).
- **Optional (owner's choice, §15):** `resources/views/marketing/`,
  `app/Livewire/Super/`, `app/Http/Controllers/MarketingController.php`. These
  are unreachable in standalone anyway (§4.4). Stripping them makes the zip
  smaller and hides the SaaS business. If stripped, the stage step must also
  stub the routes that name them — simplest is to keep them in v1.

After packing, step 5's check runs again against the zip's contents: a test
fails the build if any file matches `/(\.env$|ssh\.txt|\.sql|id_rsa)/`.
`scripts/ci/secrets-scan.sh` already exists; call it on the stage directory.

### 6.5 `RELEASE.json`, the manifest

```json
{
  "product": "true-doctor-standalone",
  "version": "1.7.0",
  "channel": "stable",
  "commit": "7de5199",
  "built_at": "2026-11-02T09:12:44Z",
  "requires": {
    "php": ">=8.2",
    "extensions": ["bcmath", "ctype", "fileinfo", "gd", "intl", "json", "mbstring", "openssl", "pdo_mysql", "sodium", "tokenizer", "xml", "zip"],
    "mysql": ">=5.7 | mariadb >=10.3"
  },
  "upgrades_from": ">=1.0.0",
  "migrations": ["2026_06_13_103100_create_permission_tables", "…", "2026_10_14_000002_existing_hospitals_are_general_hospitals"],
  "files": {"app/Models/Hospital.php": "sha256:…", "…": "…"},
  "notes": "## What's new\n- Kinds of practice …"
}
```

- `migrations` lets an installed copy show "this update runs 3 new
  migrations" before anyone presses Update.
- `files` lets the updater verify every extracted file, and later lets a
  support command say "these 2 files were edited by hand on this server".

### 6.6 Versioning

Semantic versioning, with the meaning stated for this product:

- **Patch** (1.6.1): fixes, no new migrations. Safe to apply on a busy afternoon.
- **Minor** (1.7.0): features, additive migrations only (the rule this codebase
  already follows; see the migrations from `2026_09_*` onwards).
- **Major** (2.0.0): anything requiring manual steps. Avoid.

`releases.version` is unique. A **yanked** release stays visible but is no
longer offered for download or update. Updating jumps straight to the latest
version, never one release at a time — Laravel's migrations table already
makes multi-version jumps safe.

---

## 7. Part D — Selling it on the public site

### 7.1 Products: reuse `plans`

The `plans` table, the super-admin Plans page (`Livewire\Super\Plans\Index`),
the pricing page (`MarketingController::pricing`, `planMatrix`) and
`PlatformCurrency` already handle prices, currencies and features. A one-off
product is a plan with a different billing cycle:

```php
// App\Enums\BillingCycle — add:
case OneOff = 'one_off';
```

…plus three columns on `plans` (§11): `edition` (`saas`/`standalone`),
`updates_months` (e.g. 12) and `installation_included` (bool). Example
products the owner creates on the existing Plans page:

| Plan | Cycle | Price | Updates | Notes |
|---|---|---|---|---|
| Standalone — Clinic | One-off | e.g. UGX 3,500,000 | 12 months | `limits.max_staff = 15` |
| Standalone — Hospital | One-off | e.g. UGX 9,000,000 | 12 months | No limits |
| Updates renewal | One-off | e.g. 20% of the original | +12 months | Attaches to an existing licence |
| Installation service | One-off | add-on | — | We install it for them (sets a flag; fulfilment is a human) |

The pricing page gets a second band, **"Prefer to own it?"**, listing
`edition = standalone` plans with a short comparison (the table from §1) and a
**Buy** button. Subscription plans are untouched.

### 7.2 Checkout

Buyers have no hospital and no account yet, so the flow cannot reuse
`SubscriptionCheckoutService` as-is (`gateway_logs.hospital_id` is
`NOT NULL`, migration `2026_07_19_250000`). Two options:

- **(a) Recommended:** make `gateway_logs.hospital_id` nullable in a new
  migration, and add `purpose = 'licence'` to its `meta`, which is already
  where the purpose lives (`meta.purpose = 'subscription'`). The Pesapal
  controller (`PesapalPaymentController::callback` / `ipn`) already branches
  on the gateway log; add a branch that hands `purpose = licence` to
  `LicenceCheckoutService::activate()`.
- (b) A separate `licence_orders` table that mirrors the gateway fields. More
  isolation, more code. Only if (a) proves awkward.

Flow (`/own` → `OwnershipController`, public, throttled like `enquire`):

1. The buyer picks a plan and enters organisation name, contact name, email,
   phone and the domain they will install on (optional — it can be set later
   from the portal). Validation lives in `LicenceOrderRequest::rulesFor()`, and
   the existing human check (`HumanCheckController`) protects the form like
   the other public forms.
2. A `licence_orders` row is created (`pending`), then
   `PesapalGateway::initialize()` with `tx_ref = 'LIC'.<order id>.'-'.bin2hex(random_bytes(6))`,
   kept under Pesapal's 50-character cap as the subscription code already does.
3. The Pesapal callback or IPN verifies the payment (`verify()`, exactly as for
   subscriptions) and calls `LicenceCheckoutService::activate($log)`:
   - **idempotent** on the gateway log (an IPN and a callback both arrive),
   - issues the licence (§5), with `updates_until = today + plan.updates_months`,
   - sends `LicenceIssued` mail with a **magic link** to the portal,
   - writes an activity-log entry, as other money events do.
4. The buyer lands on a **Thank you** page that says the email has gone and
   offers the portal link directly, behind a signed URL with a 30-minute life.

**Offline sales** (bank transfer, a signed contract, a ministry purchase order)
are common in this market. **Super → Licences → Issue licence** creates exactly
the same licence by hand, with a reference and the same email.

### 7.3 Customer portal

Buyers are not hospital staff and must never get an account in anybody's
hospital, so the portal is separate and passwordless:

- `/my-licence` asks for an email and sends a **magic link**
  (`URL::temporarySignedRoute('licence.portal', now()->addHours(2), …)`). No
  passwords to forget, nothing to brute-force, and the same rate limiting as
  the contact form.
- Signed in (a session key `licence_id`, not a `User`), the portal shows:

```
┌ St. Mary's Medical Centre — TD-7K2M-9QXR-4HFA-2WZP ─────────────────────────────┐
│ Standalone — Hospital · updates until 2 Nov 2027 · licensed for hms.stmarys.example │
│                                                                                  │
│ ▸ Download True-Doctor 1.7.0 (34.8 MB)      [Download]   sha256 3f1a…  ✓ signed  │
│ ▸ Your licence file                         [Download licence.json]             │
│ ▸ Installation guide                        [Open the guide]                     │
│ ▸ What's new in 1.7.0                       (release notes, rendered)            │
│ ▸ Earlier versions                          1.6.0 · 1.5.2                        │
│ ▸ Need a hand?                              [Book installation] [Contact us]     │
│ ▸ Renew updates                             [Renew — UGX …]  (shown near expiry) │
└──────────────────────────────────────────────────────────────────────────────────┘
```

- Downloads are streamed by a controller: `Storage::disk('local')` is
  `storage/app/private`, never public. Each download goes through a temporary
  signed route, recorded in `release_downloads` (licence, release, ip, at).
  Only releases with `built_at <= updates_until` and `status = published` are
  offered.
- **Change domain** is self-service once per 30 days. It re-signs the licence
  and offers the new file. A super admin can override.

### 7.4 The installation guide

One source, three outputs: the guide lives in
`resources/views/standalone/guide/*.blade.php` (or Markdown rendered with the
`league/commonmark` that Laravel already ships). It is:

1. a page on the portal, with copy buttons on every command,
2. `INSTALL.md` inside every zip (step 4 of the build writes it from the same
   source),
3. optionally a PDF via the existing dompdf setup (`x-pdf.document`), for
   buyers who print things.

Chapters: choosing a host (shared/cPanel, VPS); creating the database; uploading
and extracting (cPanel File Manager screenshots); pointing the domain at
`public/` (and the `deploy/docroot-index.php` trick for hosts that cannot,
documented in `docs/deployment-cpanel.md` — the installer reuses it, §8.3); the
installer; the cron line; mail; backups; updating; the mobile app; offline
devices; getting help.

---

## 8. Part E — Installing on the buyer's server

### 8.1 Two doors, one engine

- **Web installer** at `/install`, for the clinic administrator with cPanel
  and no shell.
- **CLI** `php artisan truedoctor:install`, for the technician with SSH. It
  asks the same questions with Laravel Prompts.

Both call one `App\Services\Install\Installer`, with methods per step
(`checkRequirements()`, `acceptLicence()`, `configureDatabase()`, `migrate()`,
`createOrganisation()`, `configureMail()`, `finish()`), so the two doors cannot
drift. This is the same rule `Navigation` applies to the web and the app.

### 8.2 Booting before there is a database

Before installation there is no `.env`, no `APP_KEY` and no database, and this
application's providers and middleware assume all three. Plan for it
explicitly:

- `bootstrap/app.php`: if `! file_exists(base_path('.env'))` and the request
  path starts with `install`, set runtime config: `session.driver=file`,
  `cache.default=array`, `queue.default=sync`, a temporary `app.key` held in
  the PHP session, and `database.default` pointed at nothing.
- A tiny `EnsureInstalled` middleware, first in the `web` group: no
  `storage/app/installed.json`, so every route except `/install*` redirects to
  `/install`. Once installed, `/install*` returns 404 forever.
- The installer views use a standalone minimal layout
  (`layouts/install.blade.php`) with the same `x-ui` kit, and **no** Livewire
  persisted shell, because the admin shell queries the database.
- Use Livewire for the installer. It works with file sessions, and the house
  rules (one `<h1>`, `wire:navigate`, toasts) still apply.

### 8.3 The steps

```
 ① Requirements ─► ② Licence ─► ③ Database ─► ④ Install ─► ⑤ Organisation ─► ⑥ Mail ─► ⑦ Scheduler ─► ⑧ Done
```

| Step | Asks | Does | Done when |
|---|---|---|---|
| ① Requirements | Nothing | Checks PHP version and every extension in `RELEASE.json.requires`; that `storage/` and `bootstrap/cache` are writable; `public/` reachable as the docroot (requests `/install/ping` from PHP itself); `memory_limit ≥ 256M`. Each line shows ✓/✗ with a one-sentence fix ("Ask your host to enable the *intl* extension") | All required checks pass |
| ② Licence | Upload `licence.json`, or paste the key and fetch it from `update_server` | Verifies the signature (§5.2) and shows customer, organisation, domain and updates-until. Warns, never blocks, on a domain mismatch | Signature valid |
| ③ Database | Host, port, name, user, password | Connects with `PDO` directly, checks the MySQL/MariaDB version, `utf8mb4`, and that the database is **empty** (refuses a non-empty one unless the admin types the database name to confirm). Writes `.env` atomically: write `.env.tmp`, `rename()` | Connection OK |
| ④ Install | Nothing | `key:generate` (with a bold note to back up `.env`: `APP_KEY` also keys the prepaid-card hash, `docs/deployment-cpanel.md`), `migrate --force` streamed in batches with a progress bar, then `StandaloneReferenceSeeder`, `storage:link`, `optimize` | All migrations ran |
| ⑤ Organisation | Hospital name, currency (the `HospitalSettings::CURRENCY_META` list), timezone (the `Steps\Profile::TIMEZONES` list), owner name, email, password | Creates the one `Hospital` and its `hospital_admin` `User` (`syncSpatieRole()`), the way `RegistrationService` does today **minus** plan, trial and subscription. `settings.practice` is left empty, so the setup wizard asks first thing | Rows exist |
| ⑥ Mail | SMTP host, port, encryption, user, password, from (skippable) | Writes `.env` and sends a test message to the owner | Test sent, or skipped |
| ⑦ Scheduler | Nothing | Shows the exact cron line for this server (`* * * * * cd <base_path> && <PHP_BINARY> artisan schedule:run >/dev/null 2>&1`) with a copy button and cPanel instructions; then **waits for proof**: the scheduler writes a heartbeat (`cache('scheduler.heartbeat')`) every minute, and the step turns green when it appears. "Skip for now" is allowed, with a persistent admin banner afterwards | Heartbeat seen, or skipped |
| ⑧ Done | Nothing | Writes `storage/app/installed.json` (`version`, `installed_at`, `licence_id`); signs the owner in; redirects to `admin.onboarding` | — |

After ⑧ the buyer is in the **existing setup wizard**, which already begins
with "Your kind of practice" (`Steps\Practice`). In practice, installation is
a few minutes of answers and then the same polished onboarding SaaS hospitals
get.

### 8.4 `StandaloneReferenceSeeder`

`deploy.sh` seeds `RbacSeeder`, `DistrictSeeder` and `PlanSeeder`. A standalone
install needs the first two and **not** plans. Make that explicit:

```php
class StandaloneReferenceSeeder extends Seeder
{
    /** Reference data a standalone install needs — nothing about selling it. */
    public function run(): void
    {
        $this->call([RbacSeeder::class, DistrictSeeder::class]);
    }
}
```

Specialty packs, the chart of accounts and sample catalogues are **code**
(`App\Support\Practice\Packs\*`, `ChartOfAccounts::STANDARD`) and are applied
from the setup wizard. That's why every release "starts with the latest": the
newest packs and accounts ship inside the code, and a fresh install runs every
migration up to the release's commit.

The generated `.env.example` in the zip:

```dotenv
APP_NAME="True-Doctor"
APP_EDITION=standalone
APP_ENV=production
APP_DEBUG=false
APP_URL=
APP_KEY=
DB_CONNECTION=mysql
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
TELESCOPE_ENABLED=false
DEMO_MODE=false
ONBOARDING_GATE=true
APP_UPDATE_SERVER=https://true-doctor.online
```

`database` drivers, not `redis`: the same lesson as `docs/deployment-cpanel.md`
("There is no Redis server"). The queue is drained by the scheduler's
short-lived worker that `routes/console.php` already defines.

---

## 9. Part F — Updating an installed copy

### 9.1 Where the owner does it

**Settings → Licence & updates** (`admin.system.index`,
`App\Livewire\System\Index`, `manage-settings`, standalone only):

```
┌ Licence & updates ────────────────────────────────────────────────────────────────┐
│ True-Doctor 1.6.0 · installed 2 Nov 2026 · licence TD-7K2M-… valid · updates to … │
│                                                                                   │
│ ● 1.7.0 is available — 3 new migrations, 41 files changed     [See what's new]    │
│   [ Back up and update ]        or  [ Upload an update file ] (no internet here)  │
│                                                                                   │
│ Backups   2 Nov 03:30 · 31 Oct 03:30 · …               [Back up now] [Download]   │
│ Health    Scheduler ✓ 1 min ago · Queue ✓ empty · Disk 12 GB free · PHP 8.3 ✓    │
│           [Download a support bundle]                                             │
└───────────────────────────────────────────────────────────────────────────────────┘
```

- **Checking** asks `GET {update_server}/api/v1/releases/latest`, sending the
  licence id and current version (a new public, throttled endpoint on the SaaS
  side; it returns the latest release the licence is entitled to, with notes,
  size, sha256, signature and a short-lived download URL). It runs daily from
  the scheduler and on demand. Failure is silent apart from "Could not check
  for updates".
- **Upload an update file** accepts the zip from the portal, for clinics
  without internet on the server.

### 9.2 The updater

`App\Services\Install\Updater`, also available as `php artisan
truedoctor:update [path-or-version]`. It is `deploy/deploy.sh` rewritten in PHP
for a buyer's server, with the same mindset: the update must prove itself
before it hands the site back.

1. **Verify** the zip: the sha256 matches; the Ed25519 signature verifies;
   `RELEASE.json.version` is newer than installed; `upgrades_from` allows the
   jump; `built_at ≤ updates_until`.
2. **Preflight**: free disk ≥ 3× the zip size, writable paths, PHP and
   extensions satisfy the new `requires`.
3. **Maintenance mode** (`php artisan down --render="errors::503"`, as
   `deploy.sh` does).
4. **Back up**: the database (`mysqldump` when available, else a chunked PHP
   dump through `DB::cursor()` per table), plus `.env` and `storage/app/private`
   (uploads, licence). Saved to `storage/app/private/backups/<timestamp>/`.
5. **Extract** into `storage/app/private/updates/<version>/`, then verify every
   file against `RELEASE.json.files`.
6. **Swap**: move the current code to `…/previous/` and the new code into
   place, file by file, preserving `.env`, `storage/`, `public/storage` and
   anything listed in a `.preserve` file. On shared hosting, a directory
   rename across the docroot is not always possible, so copy over with
   delete-of-removed-files, using the manifest to know which files those are.
7. **Migrate** (`migrate --force`), **reference seeders**
   (`StandaloneReferenceSeeder` — idempotent, like `RbacSeeder` today), **post-
   update tasks** (`finance:backfill`, and any task a release declares in
   `RELEASE.json.post_update`), `optimize`, `view:cache`.
8. **Prove it**: the same checks as the end of `deploy.sh` (roles exist, the
   front controller answers, a `/up` health route returns 200 from PHP).
9. **Up**. On any failure from step 6 on: restore the previous code, restore
   the database from step 4, bring the site up, and show what failed in plain
   words, with the log.

Every update writes an `system_updates` row (from, to, started, finished,
outcome, log), shown under the version on the same page.

### 9.3 Backups even without an update

A standalone hospital has no ops team, and losing it would be our reputation's
problem. Ship `truedoctor:backup` and schedule it nightly in standalone
(`dailyAt('03:30')` beside the existing `model:prune`), keeping 7 daily and 4
weekly copies. Offer **Download** for the newest one, and show a red banner if
the last successful backup is older than 48 hours. Optional later: push the
backup to an S3-compatible bucket the hospital configures.

---

## 10. Part G — Everything else the standalone edition must get right

| Concern | What to do |
|---|---|
| **The mobile app** | Nothing to rebuild. `true-doctor-mobo` already lets a user change the server at sign-in (`SecureSession.server`, the "Server" field in `features/auth/auth_pages.dart`, shown when not the default). The guide tells staff to tap it and enter the hospital's own URL. Optionally add a QR code on Licence & updates that encodes the server URL; the app can scan it later |
| **Offline devices / sync** | Unchanged — `docs/OFFLINE_*` all apply. Devices trust *this* server |
| **Field Mode** | Unchanged |
| **Notifications and mail** | Unchanged; mail is configured in step ⑥. FCM push stays off until the buyer supplies Firebase keys (same as SaaS today) |
| **Payments for patient bills** | The hospital's own Flutterwave keys, entered in Billing settings, are unchanged (`docs/payments-gateway.md`). Pesapal *subscription* checkout is gone |
| **Branding** | The hospital letterhead (`Settings → Hospital letterhead`, `DocumentBrand`) already brands every document. "Powered by True-Doctor" in the footer stays unless the owner sells a white-label product (§15) |
| **Super admin** | Does not exist. The owner (`hospital_admin`) holds `manage-settings` and therefore Licence & updates |
| **Support** | **Download a support bundle**: version, licence id (not the signature), PHP and extensions, `migrate:status`, the last 200 log lines with emails, phone numbers and tokens redacted, queue and scheduler state. A zip they can email us. No patient data, ever |
| **Moving to SaaS later (or back)** | Because the schema and `hospital_id` are identical, a future `truedoctor:export` / `import` can move a hospital between a standalone install and the SaaS. Not in scope, but the design keeps it possible — one more reason not to fork |
| **Telemetry** | None by default. The daily update check sends only licence id and version. Say so in the guide |
| **Time zone, currency** | From the hospital, as today |

---

## 11. Data model

All new tables live on the **SaaS side** except `system_updates` and the
`installed.json` file. None of them carry `hospital_id`: they are platform
data, like `plans` and `traffic_sessions`.

```php
// database/migrations/2026_11_xx_000001_releases_are_built_and_sold.php

Schema::create('releases', function (Blueprint $t) {
    $t->id();
    $t->string('version', 20)->unique();             // 1.7.0
    $t->string('channel', 10)->default('stable');    // stable | beta
    $t->string('status', 12)->default('queued');     // queued | building | ready | published | failed | discarded | yanked
    $t->string('commit', 40);
    $t->text('notes')->nullable();                   // markdown
    $t->unsignedTinyInteger('step')->default(0);     // 0..7, for the progress bar
    $t->unsignedTinyInteger('progress')->default(0); // 0..100 within the step
    $t->longText('build_log')->nullable();
    $t->string('artifact_path')->nullable();         // private disk
    $t->unsignedBigInteger('size')->nullable();
    $t->char('sha256', 64)->nullable();
    $t->text('signature')->nullable();
    $t->string('key_id', 16)->nullable();
    $t->json('manifest')->nullable();                // RELEASE.json as built
    $t->foreignId('built_by')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamp('built_at')->nullable();
    $t->timestamp('published_at')->nullable();
    $t->timestamps();
});

Schema::create('licences', function (Blueprint $t) {
    $t->id();
    $t->string('key', 32)->unique();                 // TD-7K2M-9QXR-4HFA-2WZP
    $t->foreignId('plan_id')->constrained();
    $t->string('customer');                          // legal name
    $t->string('organisation');                      // as it will appear in the app
    $t->string('email');
    $t->string('phone', 40)->nullable();
    $t->json('domains')->nullable();
    $t->json('limits')->nullable();
    $t->date('updates_until');
    $t->string('status', 12)->default('active');     // active | revoked
    $t->json('payload');                             // exactly what was signed
    $t->text('signature');
    $t->string('key_id', 16);
    $t->string('source', 12);                        // online | manual
    $t->string('reference')->nullable();             // bank ref, PO number
    $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamp('domain_changed_at')->nullable();
    $t->timestamps();
    $t->index('email');
});

Schema::create('licence_orders', function (Blueprint $t) {
    $t->id();
    $t->foreignId('plan_id')->constrained();
    $t->foreignId('licence_id')->nullable()->constrained()->nullOnDelete(); // set on payment; or the licence a renewal extends
    $t->foreignId('gateway_log_id')->nullable()->constrained()->nullOnDelete();
    $t->string('status', 12)->default('pending');    // pending | paid | failed | cancelled
    $t->json('buyer');                               // name, organisation, email, phone, domain
    $t->decimal('amount', 14, 2);
    $t->string('currency', 3);
    $t->timestamps();
});

Schema::create('release_downloads', function (Blueprint $t) {
    $t->id();
    $t->foreignId('release_id')->constrained()->cascadeOnDelete();
    $t->foreignId('licence_id')->constrained()->cascadeOnDelete();
    $t->string('ip', 45)->nullable();
    $t->timestamp('created_at');
});

Schema::table('plans', function (Blueprint $t) {
    $t->string('edition', 12)->default('saas')->after('billing_cycle');
    $t->unsignedSmallInteger('updates_months')->nullable()->after('edition');
    $t->boolean('installation_included')->default(false)->after('updates_months');
});

Schema::table('gateway_logs', function (Blueprint $t) {
    $t->foreignId('hospital_id')->nullable()->change();   // a licence buyer has no hospital
});
```

On the buyer's side:

```php
Schema::create('system_updates', function (Blueprint $t) {
    $t->id();
    $t->string('from_version', 20);
    $t->string('to_version', 20);
    $t->string('outcome', 12);                        // running | succeeded | rolled_back | failed
    $t->string('backup_path')->nullable();
    $t->longText('log')->nullable();
    $t->foreignId('by')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamp('started_at');
    $t->timestamp('finished_at')->nullable();
});
```

These SaaS-side tables also exist on standalone installs, empty, because
migrations are shared. That is fine, and simpler than edition-conditional
migrations, which would break the "same schema everywhere" property that makes
export and import possible later.

**Models and services (suggested names):**

```
app/Support/Edition.php
app/Support/Canonical.php
app/Support/Licensing/{Licence, Verifier}.php
app/Services/Licensing/{Signer, LicenceIssuer, LicenceCheckoutService}.php
app/Services/Releases/{ReleaseBuilder, ReleaseStager, ReleasePacker}.php
app/Services/Install/{Installer, Requirements, Updater, Backup}.php
app/Models/{Release, Licence, LicenceOrder, ReleaseDownload, SystemUpdate}.php
app/Http/Middleware/{RequireEdition, EnsureInstalled}.php
app/Http/Controllers/{OwnershipController, LicencePortalController, ReleaseDownloadController}.php
app/Http/Controllers/Api/V1/ReleaseController.php           // latest-for-licence
app/Livewire/Super/Releases/Index.php
app/Livewire/Super/Licences/Index.php
app/Livewire/Install/Wizard.php
app/Livewire/System/Index.php                               // Licence & updates
app/Console/Commands/{BuildPendingReleases, Install, Update, Backup, LicensingKeygen}.php
database/seeders/StandaloneReferenceSeeder.php
resources/views/standalone/guide/*.blade.php
docs/standalone.md                                          // once built: replaces this proposal
```

---

## 12. Delivery plan

Each phase ships on its own and leaves `main` deployable. The SaaS must keep
working throughout, and every phase ends with `composer ci` green.

| Phase | Scope | Done when |
|---|---|---|
| **1. Edition switch** | §4: config, `Edition`, `RequireEdition`, every row of §4.4, the one-hospital invariant, Navigation `edition` gate, API meta | With `APP_EDITION=standalone`, the whole test suite passes except SaaS-only tests, which are marked and skipped (§13); `/`, `/register`, `/super/*` and `/pricing` are 404 or redirect; a second hospital cannot be created |
| **2. Licensing core** | §5: `Canonical`, `Signer`, `Verifier`, `Licence::current()`, `licensing:keygen`, `PlanLimit` reading licence limits, admin banners | A licence signed with the secret verifies; one altered byte fails; an expired `updates_until` changes nothing but the update page |
| **3. Installer** | §8: `EnsureInstalled`, pre-`.env` boot, web wizard + CLI on one `Installer`, `StandaloneReferenceSeeder` | From a fresh checkout with no `.env`: the web installer reaches the setup wizard on MySQL and on SQLite; the CLI does the same non-interactively (`--no-interaction` with options) |
| **4. Releases** | §6: tables, Super → Releases, the scheduled builder with its seven steps, the allow-list, signing | A super admin stages 1.0.0 from the live server, the zip appears with a valid signature, and extracting it into an empty directory and running the installer works |
| **5. Updates** | §9: Licence & updates page, the `latest` API, upload, `Updater` with backup and rollback, nightly backups | 1.0.0 → 1.0.1 succeeds; a deliberately broken 1.0.2 (a failing migration) rolls back to 1.0.1 with data intact |
| **6. Selling** | §7: plan columns, pricing band, `/own` checkout via Pesapal, licence issuing, portal with magic links, downloads, guide, manual issuing, renewals | A sandbox Pesapal purchase ends with an email, a portal, a download and a working install, without anyone at our end touching anything |

Phases 1–3 make a sellable product, with releases built by a command. Phases
4–6 make it self-service.

---

## 13. Testing

- **Both editions in CI.** Add a trait `Tests\Concerns\RunsAsStandalone`
  (`config(['edition.name' => 'standalone'])` in `setUp`), beside
  `InteractsWithTenant`. Run the existing suites that walk the whole app —
  `SmokeRoutesTest`, `SidebarMenuTest`, `SpaNavigationHtmlTest`,
  `OnboardingWizardTest`, `OnboardingGateTest`, `Finance/*`, `Practice/*` —
  once per edition with a PHPUnit data provider, or by subclassing. SaaS-only
  tests (`MarketingSignupTest`, `RegistrationTest`, `SubscriptionGateTest`,
  `PesapalGatewayTest`, `Super*`) assert that standalone returns 404.
- **Edition boundary tests** (`tests/Feature/Edition/`): every row of §4.4 as
  one assertion; the one-hospital invariant; Navigation never offers a SaaS
  route in standalone or a standalone route in SaaS.
- **Licensing**: a known test key pair lives in `tests/fixtures/licensing/` —
  **never** the production key. Cover valid, altered, wrong key id, expired
  updates, domain mismatch, and limit enforcement.
- **Release build**: an integration test builds a release from a temporary git
  repository into a temporary directory (skipped when `git` is absent),
  asserts the allow-list (no `.env`, no `tests/`), the manifest hashes and the
  signature, and that step 5 ran.
- **Installer**: Livewire tests for every step, using a temporary base path;
  the requirement checker with fake extension lists; the database step
  against SQLite.
- **Updater**: from a fixture "installed 1.0.0" directory, update to a fixture
  1.0.1 (succeeds) and 1.0.2 with a migration that throws (rolls back, and the
  data is checked to be identical).
- **Purchase**: `PesapalGatewayTest` already fakes the gateway; mirror it for
  licence orders, including the double IPN + callback, which must issue
  exactly one licence.
- As memory notes for this project: run the long full suite once per phase in
  the background, and diagnose failures from its output rather than re-running.

---

## 14. Risks and how each is handled

| Risk | Handling |
|---|---|
| **The signing secret leaks** | It exists only in the live `.env` and one offline backup; the release allow-list cannot include `.env`; `key_id` allows rotation; a leaked key is revoked by shipping a release that drops its public key |
| **Source code is copied and resold** | PHP cannot be meaningfully protected (ionCube-style encoders break on shared hosts and on every PHP upgrade, and they make support painful). Rely on the licence agreement, a visible licensee name in the footer and on documents, update entitlement (pirated copies never update), and support only for licensed installs |
| **A build fills the disk or runs for ever** | `withoutOverlapping(30)`, a 30-minute hard stop, a disk check before step 2, and cleanup on every exit path |
| **A release ships a migration that only fails on MySQL** | Step 5 proves migrations on SQLite; add a MySQL check during the build when the host provides a scratch database (`RELEASE_BUILD_DB_*` env), which true-doctor.online can |
| **Shared hosts cannot rename directories across the docroot** | The updater copies by manifest (§9.2 step 6) and does not rely on symlinks or renames |
| **A buyer edits core files, and an update overwrites them** | `RELEASE.json.files` lets the updater list hand-edited files before it starts, and refuse unless the admin confirms. Customisations belong in settings, not in code |
| **A buyer's server is too old** | The requirement checker refuses with a plain explanation; `requires` is per release, so raising the PHP floor is announced in release notes before it bites |
| **Losing `APP_KEY` breaks prepaid-card lookups** | The installer and every backup include `.env`; the guide says it in bold; this matches `docs/deployment-cpanel.md` |
| **Standalone code paths rot because the SaaS is what we run** | Both editions in CI (§13), and the edition touches only the edges (§4.4), never clinical or financial code |
| **Licence checks block patient care** | By design they cannot (§5.3). Write a test that asserts the visits, patients and invoices pages render with no licence file at all |

---

## 15. Questions for the owner

Decide these before or during phase 6. Each has a recommended default so work
need not wait.

1. **Perpetual use with time-limited updates?** *(Recommended: yes — 12 months
   of updates included, renewable.)*
2. **Seat limits on the Clinic tier?** *(Recommended: `max_staff` only; no
   patient or bed limits, because limiting clinical records feels wrong to a
   buyer who paid.)*
3. **Strip SaaS-only code from the zip?** *(Recommended: not in v1; revisit
   once the allow-list is proven.)*
4. **White-label (remove "Powered by True-Doctor")?** *(Recommended: a
   separate, higher-priced plan flag `white_label` in licence `limits`.)*
5. **Installation service** — fulfilled by whom, within what time?
   *(Recommended: offered as an add-on plan; a super-admin task list shows
   paid-but-not-installed orders.)*
6. **Beta channel** for chosen customers? *(Recommended: yes, flag on the
   licence.)*
7. **Refund window and terms**, shown at checkout and in the licence email.
8. **Price list**, entered on the existing Plans page once phase 6 lands.
