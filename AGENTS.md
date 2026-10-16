# True-Doctor — read this first (agents and new developers)

True-Doctor is a **hospital and clinic management system**: patients, visits, orders, lab and radiology, pharmacy and stock, inpatient, billing and insurance, the books, reports. This repo is the **server**: a Laravel 12 + Livewire 3 web back office, a JSON API, and the release machinery for the one-off ("standalone") edition. The Flutter app lives in its own repo, `true-doctor-mobo` (`/Users/mac/Desktop/github/true-doctor-mobo`), and is a *client* of this one.

This page is the short version. The long version, with reasons, is the handbook in [`docs/agents/`](docs/agents/README.md). Read the handbook chapter for the area you are about to touch before you touch it.

## The approach in eight sentences

1. **One codebase, two ways to sell.** SaaS (we host, many hospitals) and standalone (a client's own server, one organisation, their brand) come from the same code, switched by `APP_EDITION`. Never fork, never copy a folder, never write "if client X".
2. **The server is the authority.** Rules, numbering, money, permissions and validation live in Services, FormRequests and Policies. Web, API and phone all call the same Service, so they cannot disagree. A screen never holds a business rule.
3. **Tenancy is safety-critical.** Every tenant row is scoped to its hospital by a global scope. Never hand-filter `hospital_id`; never add a query that can see another hospital's data.
4. **Offline-first is a promise, not a feature.** A clinician with no signal must keep working and must never lose what they typed. Anything that writes has to be safe to retry (idempotent) and honest about what is still waiting to send.
5. **Powerful behind, simple in front.** Put the intelligence in the backend (suggestions, defaults, validation, summaries) and keep the screen to the next obvious action. One summary service feeds the web page *and* the API.
6. **Compact, flat, square.** Small buttons, tight padding, slim sidebar, one blue accent, Inter. Dense but well organised: hierarchy comes from weight and hairlines, not from size and whitespace.
7. **Money and clinical writes are pessimistic and auditable.** Transactions, row locks, bcmath strings (never floats), append-only ledgers, history tables, state machines owned by enums.
8. **Prove it.** Every change ships with tests, `composer ci` stays green, and anything touching offline, money or tenancy gets a fresh-eyes review before it is called done.

## Orientation

| Where | What |
|---|---|
| `app/Services/` | Business rules. The only place that mutates domain state (`VisitService`, `BillingService`, `CardService`, `StockService`, `PatientSummaryService`…) |
| `app/Livewire/<Module>/` + `resources/views/livewire/<module>/` | Thin screens. `resources/views/components/ui/` is the kit |
| `app/Http/Controllers/Api/V1/` + `routes/api.php`, `routes/api/*.php` | The API the phone uses. Envelope in `App\Support\ApiResponse` |
| `app/Policies/`, `app/Http/Requests/` | Authorisation and validation (`rulesFor()`) |
| `app/Support/` | Cross-cutting helpers: `CurrentHospital`, `Edition`, `Navigation`, `Insights/`, `Licensing/`, `Onboarding/`, `Practice/` |
| `resources/css/admin.css` | The one stylesheet and the design tokens. Built to `public/build` (committed) |
| `docs/` | Per-module docs. `docs/decisions.md` is the append-only decision log |
| `tests/` | `Feature/` (HTTP, Livewire, Api, Offline), `Unit/` (no database) |

## Commands (this Mac)

The Homebrew `php` is broken here. Use MAMP's:

```bash
PHP=/Applications/MAMP/bin/php/php8.3.9/bin/php
$PHP artisan migrate                      # local DB is MAMP MySQL at http://localhost:8888/true-doctor
$PHP vendor/bin/phpunit tests/Unit/DesignSystemCssTest.php
npm run build                             # REQUIRED after any change in resources/css or resources/js; commit public/build
composer ci                               # Pint --test, PHPStan, empty-file + secrets scans, suite
```

The whole suite does not fit in 512 MB in one process (PDF tests). Run it **per folder or per file** (`tests/Feature/Livewire`, `tests/Feature/Api`, `tests/Unit`, `tests/Feature/Offline`…). Don't re-run slow suites to "see if it passes now": read the output and diagnose.

## Hard rules

- **Never** push, deploy or release unless the person asked. Commit locally; say what is unpushed.
- **Never** touch a client's live server except in an emergency the person has told you to fix, and then change the fewest files, keep a rollback, and verify.
- **Never** commit `.env*`, dumps, credentials. `divine-dental-info/` and `ssh.txt` are private and git-ignored; never paste them anywhere.
- **Never** edit `public/build` by hand. Edit sources, run `npm run build`, commit the result.
- **Never** add a hex colour to a view, an inline `style=""` (except computed values), a native `alert()/confirm()`, a hard link inside the SPA, or a `float` for money.
- **Don't** commit someone else's uncommitted work. Check `git status` first and add explicit paths.
- Client-facing documents are **plain PDFs and short emails**: no colourful design, no links in place of the guide.
- Non-obvious decisions go in `docs/decisions.md` (date, decision, reason). Append only.

## Handbook

| Chapter | Read it when |
|---|---|
| [`docs/agents/README.md`](docs/agents/README.md) | You want the map |
| [01 Approach](docs/agents/01-approach.md) | You are deciding *how* to build something |
| [02 Backend](docs/agents/02-backend.md) | Services, tenancy, money, the API, idempotency |
| [03 UI and density](docs/agents/03-ui-and-density.md) | Any screen, on web or phone |
| [04 Offline and the app](docs/agents/04-offline-and-app.md) | Anything the phone reads or writes |
| [05 Editions and releases](docs/agents/05-editions-and-releases.md) | Releases, licences, a client's server, hotfixes |
| [06 Testing and review](docs/agents/06-testing-and-review.md) | Before you say "done" |
| [07 Playbooks](docs/agents/07-playbooks.md) | Step-by-step recipes for the common jobs |
| [08 Lessons](docs/agents/08-lessons.md) | The mistakes already paid for. Skim before risky work |
