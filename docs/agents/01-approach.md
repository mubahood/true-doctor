# 01 · The approach

Each principle below says what we do, **why**, and **what it looks like when you get it wrong**.

## 1. One codebase, two editions

- **Do:** write every feature once. The edition (`APP_EDITION=saas|standalone`, `config/edition.php`, `App\Support\Edition`) only decides what is *available*: SaaS has sign-up, plans, the public site and the super-admin panel; a standalone copy has the installer, licence and updates screens, nightly backups, and exactly one organisation.
- **Why:** a second product doubles every fix. A client's copy is built from what already runs on SaaS, so clients get tested code.
- **Wrong looks like:** a client-specific branch, a copied view, `if ($hospital->name === …)`, a feature that works in SaaS and 500s on a copy because it assumed many hospitals or a queue worker or `exec()`.
- **Check:** a standalone copy runs on cPanel shared hosting: no shell, cron once a minute (live: every 19), `classmap-authoritative` autoload, MyISAM-by-default MySQL. Features must survive that. See [05](05-editions-and-releases.md).

## 2. The server decides; clients show

- **Do:** put rules in Services, validation in FormRequests (`rulesFor()`), authorisation in Policies. A Livewire component, an API controller and the phone's sync all call the same Service.
- **Why:** surfaces drift when each re-implements a rule. The phone is offline-capable, so it *predicts*, but the server's answer wins.
- **Wrong looks like:** totals computed in a Blade view or in Dart, status transitions written as `->update(['status' => …])`, a rule that exists on the web and not in the API.
- **Pattern:** thin controller → shared Service + Policy → Resource → `ApiResponse`. Domain exceptions (`\DomainException`, `SlotUnavailableException`, `InsufficientStockException`, `ClosedPeriodException`, `PlanLimitExceededException`) become 422 with a message a person can read.

## 3. Tenancy first

- **Do:** every tenant model uses `BelongsToHospital` (global `HospitalScope`, auto-fills `hospital_id`). The tenant is resolved by `ResolveHospital`, which runs *before* route-model binding.
- **Why:** the worst possible bug in a hospital system is one hospital seeing another's patients. We hit it once (binding ran before the tenant was resolved) and fixed it at the pipeline.
- **Wrong looks like:** `Patient::where('hospital_id', …)`; a `unique`/`exists` validation rule with no hospital filter (raw queries ignore Eloquent scopes); a super-admin query that forgets `withoutGlobalScope`; an API test that only checks the happy path.
- **Always test:** "tenant B cannot see tenant A" for every new model, endpoint and screen.

## 4. Powerful behind, simple in front

- **Do:** spend effort on defaults, derived values, suggestions and summaries on the server, so the screen needs fewer decisions. Examples that exist: `PatientSummaryService` (one source for the patient page *and* `GET patients/{uuid}/summary`), `app/Support/Insights/` ("Worth a look" suggestions), starter data per kind of practice (`app/Support/Practice`, `StarterData`), the onboarding gate.
- **Why:** staff are busy and not technical. The best screen shows the next obvious action and what they need to decide it.
- **Wrong looks like:** a settings page with twelve toggles and no recommendation; a list that makes people compute the figure the row is really about (the quick view exists to carry that figure).
- **Suggestions are rules, not magic:** each is a class with a clear trigger, a plain sentence and one action, hidden when it does not apply, and covered by a test.

## 5. Offline-first is a promise

- **Do:** assume the phone is offline half the time. Reads fall back to a device copy that says when it was fetched; writes go to an outbox and sync later; nothing the person typed is ever discarded without them choosing it.
- **Why:** the app is used on wards and in clinics with poor signal; losing a vitals round is a patient-safety issue, not an inconvenience.
- **Wrong looks like:** a form that clears itself on a failed request; a write endpoint that is not safe to retry; a screen that spins forever offline instead of showing the last copy.
- Details and the exact rules: [04](04-offline-and-app.md).

## 6. Money and clinical state are boring on purpose

- Money is a **scale-2 decimal string** end to end (bcmath), never a float. Every money movement is in a DB transaction with `lockForUpdate` on the row; ledgers are append-only with a `balance_after` snapshot (`CardService`, `InsuranceLedgerService`, the books under `Services/Finance`).
- Status changes are **state machines owned by enums** (`transitionsTo()` / `canTransitionTo()`), applied only through the Service, which writes a history row. The UI builds its buttons from `transitionsTo()`, so it cannot offer an illegal move.
- Closed financial periods refuse writes (`ClosedPeriodException`).
- **Why:** these are the bugs that cost real money and trust, and the legacy system's top bug source was "fat models doing billing as a side effect of save()". Services and explicit calls only.

## 7. Compact, flat, square

- One accent (blue `#0A6EBD`), Inter, square corners, hairlines, no shadows except dialogs. Controls are ~28–30 px, card padding 8–12 px, body 12–13 px, sidebar rows ~30 px. The person said, plainly, that big chips, uppercase destinations and generous padding waste screen room.
- Hierarchy comes from weight, colour tokens and hairlines, **not from size and space**. Dense is allowed; disorganised is not.
- Full rules and tokens: [03](03-ui-and-density.md).

## 8. Finish properly

- "Done" means: works, tested, `composer ci` green (or the per-folder equivalent), assets built, docs updated, decisions logged, and the risky parts reviewed by someone who did not write them. See [06](06-testing-and-review.md).
- Say plainly what you did **not** verify (a browser check you could not do, a device you do not have, a deploy you did not make).

## How to make a decision when the code is silent

1. Is there a precedent? `grep` for the nearest existing screen/service and copy its shape.
2. Does it protect tenancy, money, or a patient's data? Choose the stricter option.
3. Does it make the person do less? Choose that.
4. Is it reversible? If not, ask first (push, deploy, delete, send, publish).
5. Otherwise pick the simplest option, write it in `docs/decisions.md`, and continue.
