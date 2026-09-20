# Offline-first — implementation progress

Live checklist for [`OFFLINE_FIRST_IMPLEMENTATION_PLAN.md`](OFFLINE_FIRST_IMPLEMENTATION_PLAN.md).
Statuses: `NOT_STARTED` · `IN_PROGRESS` · `IMPLEMENTED` · `TESTING` · `PASSED` · `FAILED` ·
`BLOCKED` · `COMPLETED`.

**`COMPLETED` means the tests were actually executed and actually passed.** Where something could
not be executed in this environment it is marked `BLOCKED` with the reason, never `PASSED`.

---

## Phase 0 — Sign-off

| Task | Status |
|---|---|
| Discovery and classification | COMPLETED |
| Master plan written | COMPLETED |
| Decisions logged | COMPLETED |
| Approved by user | COMPLETED — 2026-09-19 |

---

## Phase 1 — Foundation (local persistence) · **COMPLETED**

Vitest suite: **43 passed**, actually executed (`npm run test:js`).

| Task | Status | Files | Tests |
|---|---|---|---|
| Toolchain: Dexie 4.4.6, Vitest 5, fake-indexeddb | COMPLETED | `package.json`, `vitest.config.js`, `tests/js/setup.js` | — |
| Local schema v1 (25 stores) | COMPLETED | `resources/js/offline/db.js` | `foundation.test.js` (3) |
| Schema migration harness v1→v2→v3 | COMPLETED | — | `migrations.test.js` (5) |
| Entity envelope + UUID/ULID | COMPLETED | `resources/js/offline/ids.js` | `foundation.test.js` (4) |
| Repositories (atomic write + enqueue) | COMPLETED | `resources/js/offline/repository.js` | `foundation.test.js` (11) |
| Clock-skew guard | COMPLETED | `resources/js/offline/clock.js` | `foundation.test.js` (4) |

---

## Phase 2 — Outbox · **COMPLETED**

| Task | Status | Notes |
|---|---|---|
| Operation model + 7 states | COMPLETED | `outbox.js` |
| Atomic entity-write + enqueue (I-5) | COMPLETED | Proven by a failing-transaction test |
| Dependency ordering (`depends_on`) | COMPLETED | **Bug found and fixed** — see Known issues |
| Cascade refusal to dependants | COMPLETED | `rejectDependantsOf()` |
| Backoff with jitter | COMPLETED | 5s→1h, ±20%, cap 24 attempts |
| Survives reload / restart / crash (I-7) | COMPLETED | `durability` tests reopen the database |
| Never silently drops work (I-3) | COMPLETED | Exhausted → `failed`, still in the outbox |

---

## Phase 3 — Server sync infrastructure · **COMPLETED**

PHPUnit: `OperationLedgerTest` **13 passed**.

| Task | Status | Notes |
|---|---|---|
| `devices` table + registration + revocation | COMPLETED | 5 migrations, all run on live MySQL |
| `sync_operations` ledger (unique `operation_id`) | COMPLETED | The index IS the gate, not a PHP check |
| `sync_sessions`, `sync_conflicts` | COMPLETED | |
| `sync_revision` columns + per-hospital counter | COMPLETED | `Support\SyncRevision`, `SyncRevisionObserver` |
| Backfill for pre-existing rows | COMPLETED | Migration `..._000005`; 0 rows left unrevisioned |
| `version` columns (3 tables only) | COMPLETED | |
| `uuid` on the three bedside tables | COMPLETED | Migration `..._000004` |
| Idempotency gate (I-1, I-2) | COMPLETED | **Bug found and fixed** — see Known issues |
| `GET /sync/status` | COMPLETED | |

---

## Phase 4 — Push · **COMPLETED (server)**

PHPUnit: `SyncPushTest` **29 passed**.

| Task | Status | Notes |
|---|---|---|
| `POST /sync/push` batch endpoint (cap 200) | COMPLETED | Oversized batch refused, never truncated |
| Per-operation transactions | COMPLETED | One bad op does not roll back the batch |
| Handlers through the existing Services | COMPLETED | `PatientService`, `AdmissionService` untouched |
| Accept / reject / conflict / already_processed | COMPLETED | |
| Sequence allocation at sync (I-11) | COMPLETED | `patient_no` assigned server-side |
| Per-operation authorisation (I-15) | COMPLETED | Uses the SAME abilities as the online panels |
| Device gating (unregistered / revoked / protocol) | COMPLETED | |
| Client push loop | IN_PROGRESS | Phase 6 |

---

## Phase 5 — Pull · **COMPLETED (server)**

PHPUnit: `SyncPullTest` **20 passed**, including a 450-record multi-page
integrity run that asserts nothing is delivered twice.

| Task | Status | Notes |
|---|---|---|
| Revision on every write, online included | COMPLETED | **Two bugs found and fixed** — see Known issues |
| Signed cursors, per device, per hospital | COMPLETED | A forged cursor reads as "start again" |
| `GET /sync/pull` pagination + interleaving | COMPLETED | Streams merged by revision, not concatenated |
| Persist-then-advance (I-4) | COMPLETED | Server never moves a device's cursor itself |
| Tombstones | COMPLETED | Soft delete gets its own revision |
| Permission-scoped streams | COMPLETED | Reception gets no bedside records |
| `GET /sync/reference` | COMPLETED | Carries no stock balances |

---

## Phase 6 — Conflicts + the client sync engine · **COMPLETED**

Vitest: `sync-engine.test.js` **24 passed**, `connectivity.test.js` **10 passed**.

| Task | Status | Notes |
|---|---|---|
| Version checks | COMPLETED | `base_version` vs the server's |
| **Three-way field merge** | COMPLETED | **Bug found and fixed** — version inference was too conservative to be useful; the device now sends `base_fields` |
| Never-auto-merge list | COMPLETED | `dob`, `sex`, `blood_type`, `allergies`, `chronic_conditions` |
| Conflict store + both sides kept | COMPLETED | Field names only, never values |
| Client push/pull engine | COMPLETED | Lost response, refusal, cascade, revocation, 401 |
| Transport with honest failure modes | COMPLETED | "No answer" ≠ "an answer we did not like" |
| Multi-tab lock (Web Locks + lease) | COMPLETED | Concurrency test proves one at a time |
| Connectivity state machine | COMPLETED | Learns from real requests, not `navigator.onLine` |
| **Resolving a conflict** | COMPLETED | `conflicts.js` — keep mine / keep theirs, both audited. 14 Vitest + 2 PHPUnit covering the full cycle |
| Closing one the server settled elsewhere | COMPLETED | A pull that moves the version past the disputed one ends the argument |
| Manual resolution UI for lab results | NOT_STARTED | Lab results are not an offline entity yet |

---

## Phase 7 — Field Mode UI · **MOSTLY COMPLETED**

PHPUnit: `FieldModeTest` **17 passed**. Vitest: `workflows.test.js` **35 passed**.

**Seven** of the ten workflows in plan §4.9 are built end to end and tested:
register a patient · correct their details · vitals · nursing note ·
medication · report a lab result · **write a clinical note**. Plus deciding a
conflict, which the plan did not count as a workflow but which is the one thing
without which the queue never drains.

The remaining three — dispensing intents, appointment check-in and opening a
visit — need server handlers first. Opening a visit stays deliberately out:
`visit_no` is allocated by `Support\Sequence` under a lock and opening one
bills a consultation.

| Task | Status | Notes |
|---|---|---|
| `/field` route + shell | COMPLETED | Renders with no patient data and no CSRF token |
| Vite entry, own Alpine, `tb-*` reuse | COMPLETED | `admin.js` must never import Alpine |
| Device set-up screen | COMPLETED | Names what is kept and what is not. The machine NAME is derived and pre-filled, never demanded — one press does the lot |
| Status bar (state · pending · attention) | COMPLETED | |
| Clock, storage and revocation warnings | COMPLETED | |
| Honest "needs a connection" for billing | COMPLETED | Hidden screens make people think they are lost |
| Ward round: vitals · nursing note · medication | COMPLETED | `field/partials/ward.blade.php`. Mirrors the panel admissions list: filter bar, `tb-table`, allergies as a column |
| Register a patient | COMPLETED | Provisional label until the server issues a number. Mirrors `livewire/patients/_fields.blade.php` section for section |
| Find a patient + correct details | COMPLETED | Says plainly when somebody is simply not on this device |
| "Not yet sent" list | COMPLETED | What each record IS, never what is in it |
| Bench: report a lab result | COMPLETED | `field/partials/bench.blade.php` · `LabResultHandler` |
| Notes: the clinical narrative | COMPLETED | `field/partials/notes.blade.php` · `VisitClinicalHandler`. The form opens holding what is already on the visit, because writing over a colleague unseen is what the whole mechanism exists to avoid |
| Decisions: resolve a conflict | COMPLETED | `field/partials/conflicts.blade.php` · `ConflictResolver` |
| Dispensing intents · appointment check-in · opening a visit | NOT_STARTED | No server handler yet |

---

## Phase 8 — Service worker · **COMPLETED (asserted, not browser-run)**

| Task | Status | Notes |
|---|---|---|
| Shell network-first, hashed assets cache-first | COMPLETED | Six rules, each from the 2026 stale-asset incident |
| Update prompt, no auto-`skipWaiting` | COMPLETED | `skipWaiting()` appears once, in the message handler |
| Kill-switch (`UNREGISTER`) | COMPLETED | Withdrawable in one deploy |
| Never touches IndexedDB (I-6) | COMPLETED | True by construction — the file has no DB code |
| Never caches API or Livewire | COMPLETED | |
| **A real browser update cycle** | BLOCKED | No browser automation here. See Environment limitations |

---

## Phase 9 — Offline authentication · **COMPLETED (redesigned)**

| Task | Status | Notes |
|---|---|---|
| Device registration, explicit and audited | COMPLETED | `POST /sync/register`, activity-logged |
| Revocation with a required reason | COMPLETED | Device wipes on contact; reason shown |
| Permission enforced per operation (I-15) | COMPLETED | Live permissions, not a cached snapshot |
| No password stored anywhere | COMPLETED | Asserted in the security review |
| Sign-out refuses while work is unsent (I-13) | COMPLETED | |
| The API can see a browser session at all | COMPLETED | `statefulApi()`. **This was missing, and it made the whole feature inert — bug 16** |
| The client sends its cookie and CSRF token | COMPLETED | `credentials: 'same-origin'` + `X-XSRF-TOKEN`, read fresh from the cookie jar per request |
| An expired session is reported, not swallowed | COMPLETED | 401 and 419 both raise `AUTH_EXPIRED`; the shell says so; nothing is discarded |
| **PIN / Web Crypto token wrapping** | WONT_DO | There is no stored token to wrap. The session cookie is `HttpOnly` and unreadable by script — strictly stronger than a wrapped token sitting in IndexedDB. Reasoned through in `OFFLINE_SECURITY.md` §3 |
| **12-hour capture session expiry** | NOT_STARTED | The Laravel session lifetime governs today. A shorter offline-specific window is designed, not built |

---

## Phase 10 — Attachments · **NOT_STARTED**

| Task | Status | Notes |
|---|---|---|
| Blob store + quotas | NOT_STARTED | The `blobs` store exists in the schema; nothing writes to it |
| Chunked resumable upload | NOT_STARTED | |
| SHA-256 verification | NOT_STARTED | |

Deliberately last: attachments are the fastest way to exhaust a quota and the
slowest thing to sync, and nothing else depends on them.

---

## Phase 11 — Status & dashboard · **COMPLETED**

`/admin/offline` — **Offline readiness**, added after the ERR_FAILED report. The
fleet page (`/admin/offline-devices`) answers "who has a copy and can I take it
away"; this answers the question a clinician actually has, about the machine in
front of them, before they walk out of signal.

| Task | Status | Notes |
|---|---|---|
| Seven live checks, worst-answer verdict | COMPLETED | Worker · app downloaded · device registered · records and their age · room · clock · anything waiting |
| One button that does all of it | COMPLETED | Register then download the app then the records, each step reported as it starts |
| Per-check fix buttons | COMPLETED | Send now · Open · Reload · Check again |
| How much there is to download | COMPLETED | PullService::availableFor(), the SAME permission-scoped streams a real pull sends |
| Remove this device copy | COMPLETED | Refuses while anything is unsent, and says how many |
| Gated on access-admin, not manage-users | COMPLETED | The people who work offline are not the people who administer users |


PHPUnit: `DeviceDashboardTest` **10 passed**.

| Task | Status | Notes |
|---|---|---|
| Connectivity state machine (7 states) | COMPLETED | |
| Field Mode status bar | COMPLETED | Pending count is never subtle |
| `/admin/offline-devices` | COMPLETED | Who holds a copy, and taking it away |
| Device quick view with its operation history | COMPLETED | What it did, never what was in it |
| Revocation with a required reason | COMPLETED | |
| Diagnostics bundle, payload-free | COMPLETED | `OfflineClient.diagnostics()` |
| Retry-from-the-dashboard controls | PARTIAL | `retry()` / `retryAllFailed()` exist on the client; no admin button yet |
| **Decisions tab in Field Mode** | COMPLETED | Both sides of each contested field, side by side |

---

## Phase 12 — Hardening · **COMPLETED**

| Task | Status | Notes |
|---|---|---|
| Invariants I-1…I-15 | COMPLETED | `DataIntegrityInvariantsTest` — one test each, plus a guard that the plan and the register agree |
| Scenario matrix 1…20 | PARTIAL | 16 PASS, 4 PARTIAL. Table in `OFFLINE_TESTING.md` |
| Security review | COMPLETED | `OFFLINE_SECURITY.md`, with limitations stated |
| Performance, measured on live MySQL | COMPLETED | 1,820 rows / 10 pages / 1,062 ms / 0 duplicates |
| Realistic seed data | COMPLETED | `OfflineDemoSeeder` — 1,200 patients through the real Services |
| Online regression | COMPLETED | No existing test broken |
| Documentation set | COMPLETED | 6 documents |

---

## Bugs found and fixed while building

Each was found by a test written to try to break the thing, not by the happy path.

| # | Where | What was wrong | How it was caught |
|---|---|---|---|
| 1 | `outbox.js` `due()` | A dependency that had been **rejected** or was **in flight** looked satisfied, because the query only read `pending`. A vitals round whose visit the server had just refused would have been sent alone and rejected confusingly. | Test written specifically for a rejected parent |
| 2 | `OperationLedger::once()` | **No transaction around the handler.** A handler that wrote two rows and failed on the second left the first behind — and the retry wrote it again. A duplicate the idempotency gate cannot catch, because the operation genuinely never completed. | A test named "leaves no half-applied record" that did not assert it; adding the assertion failed immediately |
| 3 | `PatientService::createWithNumber` | `$patient->uuid = Str::uuid()` **overwrote a caller-supplied uuid unconditionally**, so every offline registration arrived with an id the device had never seen. The device could not match its local record, and every retry would have made another patient. Invariant I-8. | Push test comparing the stored uuid with the one sent |
| 4 | `AppendOnlyClinicalHandler` | Invented a permission (`nursing.record`) that does not exist in `RbacSeeder`, so every clinical operation was silently forbidden. Now uses the same `visits.vitals` / `ipd.manage` the online panels gate on. | Push test asserting `accepted` |
| 5 | Same | Treated `VitalRound` as belonging to a **visit**. It belongs to an **admission** (`admission_id NOT NULL`); outpatient triage vitals are columns on the visit, written by `VisitService::recordVitals`. Two different things wearing one name. | `DESCRIBE vital_rounds` after a test showed 0 rows written |
| 6 | `PatientHandler::merge` | Inferred "the server changed this field too" from version numbers, which cannot explain an edit made through the online panel — so it assumed the worst and **every online edit blocked every offline edit** of that patient. Replaced with a real three-way merge: the device sends `base_fields`, the values it last had confirmed. | Merge test expecting a phone number to survive |
| 7 | `SyncRevisionObserver` | `saving` fires **before** `BelongsToHospital` fills `hospital_id` on create, so every newly created record got no revision and **no device would ever pull it**. Now falls back to `CurrentHospital`, the same source the trait uses. | Pull test for a device's own admissions |
| 8 | Same | A **soft delete never got a revision**: `runSoftDelete()` builds its own UPDATE and drops anything set on the model, so a device would hold an archived patient for ever with nothing to say it had gone. Now written with the query builder in `deleted`. | Tombstone pull test |
| 9 | Migration `..._000003` `down()` | SQLite refuses to drop a column an index still names. Broke `OrderMigrationTest`, the test that exists to catch exactly this class of migration damage. | Full-suite regression, 5 failures |
| 10 | `PatientHandler::update` | A record **created and edited before its first sync** arrived with `base_version` 0 and no base fields, went down the merge path, and produced a conflict for a record nobody else had touched. `base_version` 0 now means "this device is the only writer". | A Vitest expectation that turned out to be wrong, and asking why |
| 11 | `VitalRound`, `NursingNote`, `MedicationAdministration`, `Patient`, `Visit` | `client_created_at` had a `@property Carbon` docblock and **no cast**, so the model handed back a string and every `->format()` on it was a fatal waiting to happen. | The full-shift test dereferencing it |
| 12 | `field-sw.js`, `worker.js`, `transport.js` | **Every path was hardcoded from the origin root** — `/field`, `/api/v1`, `/field-sw.js`. This installation is served from `/true-doctor/`, so the worker never registered, the API was never reachable, and **offline mode silently did not work at all**. Nothing threw and no test failed. Now derived from a `td-base` meta tag, and in the worker from `self.location.pathname`. | The user turning the server off and getting the browser's error page |
| 13 | `field-sw.js` | A failed navigation to `/admin` fell through to the browser's "this site can't be reached". The plan (§2.1) called for a door into Field Mode and it was never built. The worker now answers a failed admin navigation with a generated page — **still never caching admin HTML**, which is a different thing. | Same report |
| 14 | `LabService::order()` | New lab order items were created with **no uuid**, so a bench device could never name which test it was reporting against. The backfill migration covered existing rows and nothing minted one for new ones. | The lab handler's own tests, all failing with a 422 |
| 15 | Laravel's `VerifyEmail` / `ResetPassword` | Both send **inside the web request**, so a mail server refusing a login produced a 500 and a Symfony stack trace carrying the mailbox address. Every other notification in the project already queued. | The user hitting it |
| 16 | `bootstrap/app.php`, `transport.js` | **The API routes had no session middleware at all.** `auth:sanctum` could therefore only ever see a bearer token, and the Field Mode client sends none — so a fully signed-in browser got `200` on `/admin` and `401` on every sync request beside it. Register, push, pull, ack: all of them. **Offline mode had never synced once, in any browser.** Nothing threw and all 129 offline tests passed, because every one of them authenticates with `Sanctum::actingAs()`, which sets the user on the guard directly and so never needs a session to exist. | Reading the auth path while looking for somewhere to put a PIN — then proving it with curl against the running server, twice |
| 17 | `sync-engine.js` | A `419` — which is what a dead session actually produces on a write, because it fails the CSRF check before the auth check — was handled as a generic retryable error. A signed-out device would have backed off and retried for ever and told nobody why its queue never moved. | Writing the 419 case after curl returned one |
| 18 | `sync-engine.js` | The fix for 17 emitted `AUTH_EXPIRED` and then `break`, which fell through to `SYNC_PULL_COMPLETED` — and a completed round is exactly what clears the warning. It would have raised and cleared the banner in the same breath. Caught before the test was written; the test was written anyway and fails without the `return`. | Reading my own change |
| 19 | `devices.pull_cursor` | `varchar(255)` holding an **encrypted** cursor. Laravel's envelope alone is ~210 characters before the payload and the real cursor measured 288, so `POST /sync/ack` answered **500 on every call, for every device**, with `Data too long for column 'pull_cursor'`. Now `text`. **SQLite does not enforce VARCHAR lengths at all**, so every test passed and will keep passing either way — the second SQLite-versus-MySQL trap here, after the table-rebuild cascade. | Running a full register → push → replay → pull → ack cycle against the live MySQL server with a real login cookie |
| 20 | `field-sw.js` | `cacheFirst` ended in a bare `await fetch(request)` with **no catch**. Offline, with the asset not cached, that promise rejected — and a rejected `respondWith` is exactly what a browser reports as **`ERR_FAILED`**. Clicking the worker's own "Continue in Field Mode" door landed on the browser error page. | The user turning the server off and following the door |
| 21 | `field-sw.js` | `install` cached the shell HTML and **none of the hashed assets it names**. Those were only ever cached as a side effect of being fetched online, so after any `npm run build` — three of them that day — the cached HTML pointed at a bundle that was neither in the cache nor on the server. Bug 20 turned that from a possibility into a certainty. Shell and assets are now cached together or not at all. | Reading the install path while looking for bug 20 |
| 22 | `field-sw.js` | The door asked `cache.match(SHELL_URL)` and promised **"Field Mode works without one"** on the strength of it, while the destination needed the assets too. A door that promises something has to ask the same question its link will. | Same report — the two answers disagreeing IS the bug the user saw |
| 23 | `public/sw.js` | The retired kill-switch deletes **every** cache on its way out, Field Mode's shell included. A browser that still has it registered would lose offline mode with no explanation. Now skips `td-field-*`. | Looking for anything else that could empty the cache |
| 24 | `field-sw.js` | **The one that actually caused the ERR_FAILED.** `cache.add()` followed `/field` to the LOGIN page — the session had lapsed by the time the background install ran — and stored the perfectly good 200 it landed on. Serving a `redirected` response to a navigation, whose redirect mode is `manual`, is a network error: *"a redirected response was used for a request whose redirect mode is not follow"*. The door said "Field Mode works" because `cache.match` found something. Guarded on BOTH sides now: never stored, never served, and a poisoned entry is deleted on sight. The cached shell must also carry `td-user`/`td-hospital`, because the login page is a valid 200 with build assets of its own and would pass every other check. | The user pasting the console warning |
| 25 | `readiness.blade.php`, `admin.js` | Five design-system classes invented rather than looked up — `tb-badge-ok`, `tb-badge-bad`, `tb-sr-only`, `tb-mb-1`, `tb-spacer`. They would have rendered unstyled. Caught by `DesignSystemCssTest`, which names each one. | The full suite, on a page added the same day |
| 26 | `readiness.blade.php`, `index.blade.php` | Two new links without `wire:navigate`. Caught by `SpaNavigationHtmlTest`. The one to `/field` is now exempted by name — Field Mode is a separate application, and a `wire:navigate` to it would try to swap a client-rendered shell into the Livewire SPA. | Same run |
| 27 | `vite.config.js` | **The origin-root bug for the third time, and the first one no source grep could catch.** Vite bakes its `base` — default `/build/` — into the module-preload helper, so every lazily-imported chunk was preloaded from the origin root and 404'd. The ENTRY files loaded perfectly throughout, because Laravel's `@vite` builds those urls from `APP_URL` and never consults Vite's base. Introduced by the dynamic `import()` on the readiness screen. Now `base: './'`, which resolves against the importing chunk and is right wherever the app is mounted. The guard asserts the BUILT artefact, not the config. | The user pasting four 404s from `/admin/offline` |
| 28 | `index.js`, `field.js`, `shell.blade.php` | A browser with storage blocked — Brave shields, a private window — does not expose `indexedDB`. Dexie threw `MissingAPIError`, which surfaced as an unhandled Alpine expression error and left Field Mode on **"Opening your offline copy…" for ever above a blank page**. The reason was in the console and nowhere a clinician would look. Now checked before Dexie is asked, reported as a sentence naming the likely setting, and added as the FIRST readiness check. | The user pasting the Alpine error |
| 29 | `readiness.js`, `admin.js`, `readiness.blade.php` | **The diagnostic needed a working system in order to run.** When `OfflineClient.boot()` threw — which bug 28 made likely — the readiness screen rendered a verdict of "unclear", an EMPTY table of checks, and three buttons that silently did nothing, because every handler returned early on a null client. The one page whose whole purpose is to explain a broken device was useless on a broken device. `Readiness` now takes a null client: the checks that need no local copy still answer, and they are precisely the ones that explain the failure; the rest say "could not be checked" rather than vanishing; and the reason is shown at the TOP rather than buried under the fold. | The user: "the buttons in this page are not responsive" |
| 30 | `db.js` | **The root cause of every "offline does not work" report, and the worst bug in this feature.** `openOfflineDb` always passed `{indexedDB: options.indexedDB, IDBKeyRange: options.IDBKeyRange}` to Dexie. With no overrides — which is EVERY call in the real application — both are `undefined`, and Dexie merges options over its defaults with `Object.assign`, which copies an `undefined` value just as happily as a real one. So `globalThis.indexedDB` was overwritten with nothing and Dexie threw `MissingAPIError: IndexedDB API missing` against a browser whose IndexedDB was working perfectly. **The local database had never opened in a browser once.** No test could see it: every test injects fake-indexeddb explicitly, so the key always had a value and the defaults were never consulted — the production path was the one path never taken. | The user running the probe: `indexedDB exposed: true`, `open OK, storage works`, while the app insisted it was missing |
| 31 | `field/partials/workflows.blade.php`, `conflicts.blade.php` | Field Mode's tab strip carried `.tb-seg` on the container and **nothing on the buttons**. `.tb-seg` is a bare flex row; every scrap of the appearance lives on `.tb-seg-btn`, so seven raw browser-default buttons rendered jammed together on a screen a clinician works from. The conflicts table was also missing its `.tb-table-wrap`. `DesignSystemCssTest` could not see either: `tb-seg` IS defined, so every class used was accounted for — what was missing was the other half of a pair, which is a different question. | The user: "this looks like a real total mess" |
| 32 | every Field Mode form | **A form that contradicted the screen.** The register form reported "A first name is needed" with "Muhindo" plainly in the box: the browser had autofilled it, and an autofill can set an input without Alpine seeing it, so `x-model` held nothing. Every form now reads `FormData` at submit — the form cannot disagree with what somebody is looking at. | The user, with a screenshot of the contradiction |
| 33 | `workflows.js` | The offline rules were hand-rolled and did not match the server's: a **date of birth in the future was accepted**, then refused on sync hours later. Now transcribed from the `FormRequest` classes in `validation.js`, field for field, with errors keyed by field and shown under the control the way the panel does it. | Same report — "20/09/2026" as a date of birth |

## Final verification

Run at the end, everything together.

| | Result |
|---|---|
| PHPUnit, whole suite | **1,774 passed** (6,707 assertions) — up from 1,609 at the start, **zero regressions** |
| Vitest | **142 passed** |
| Offline tests specifically | **158 PHPUnit** + **142 Vitest** |
| PHPStan level 5 | No errors |
| Pint | Pass |
| Vite build | Clean |
| Live MySQL migrations | 7 run, row counts verified before and after |
| Live MySQL load | 1,200 patients seeded through the real Services; full pull = 1,820 rows / 10 pages / **1,062 ms / 0 duplicates** |
| Full-shift end-to-end | 6 scenarios, including a lost response mid-shift |
| **A real browser round trip, by hand** | login → `/field` → register → status → push → **replay** → pull → ack, driven with `curl` against live MySQL using a real session cookie. See `OFFLINE_TESTING.md` §6b. This is what found bugs 16 and 19, and neither was visible to any of the numbers above |

## Environment limitations

Stated rather than left to be discovered. **None of these is marked passed anywhere.**

| Item | Status | Reason |
|---|---|---|
| Real browser E2E (Playwright) | BLOCKED | No browser automation in this environment |
| A real service-worker update cycle | BLOCKED | Same. I-6 is held by construction — the worker contains no database code at all — which is stronger than a test but is not the same as having watched it happen |
| Storage quota actually exhausting | BLOCKED | Needs a real browser under pressure |
| 10,000 pending operations | NOT_RUN | 220 in-engine and 1,820 pulled were run |
| Attachments | NOT_BUILT | Plan phase 10 |
| PIN / Web Crypto token wrapping | NOT_BUILT | Plan phase 9. Registration and revocation are built |
| Lab results, dispensing, check-in | NOT_BUILT | Need server handlers first |

