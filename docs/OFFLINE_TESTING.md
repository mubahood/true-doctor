# Offline testing

How the offline system is tested, what each layer proves, and what has **not**
been tested in this environment.

---

## 1. Running it

```bash
npm run test:js                                   # Vitest — the client
php artisan test tests/Feature/Offline/           # PHPUnit — the server
php artisan test                                  # everything, incl. regression

php artisan db:seed --class=OfflineDemoSeeder     # 1,200 patients through the real Services
OFFLINE_SEED_PATIENTS=10000 php artisan db:seed --class=OfflineDemoSeeder
```

---

## 2. The layers

| Layer | Tool | Files | What it proves |
|---|---|---|---|
| Local DB, ids, clock, repositories, outbox | Vitest + fake-indexeddb | `tests/js/foundation.test.js` | A record and its operation are written together or not at all; nothing survives a restart by accident |
| Local schema migrations | Vitest | `tests/js/migrations.test.js` | 50 pending operations survive v1→v2→v3, and a **failed** migration leaves the device where it was |
| Sync engine, transport, locking | Vitest | `tests/js/sync-engine.test.js` | Lost responses, refusals, conflicts, revocation, expired sessions, multi-tab |
| Connectivity | Vitest | `tests/js/connectivity.test.js` | Wifi-up-server-down is reported as offline, not online |
| Idempotency | PHPUnit | `OperationLedgerTest` | Invariants I-1 and I-2, including concurrent replay and a handler that throws |
| Push | PHPUnit | `SyncPushTest` | Real batches through real Services and Policies |
| Pull | PHPUnit | `SyncPullTest` | Cursors, paging, tombstones, permission scoping, 450-record no-duplicate run |
| **The service worker, executed** | Vitest | `tests/js/field-worker.test.js` | The source evaluated in a sandbox with stub `self`/`caches`/`fetch`. Every decision about what to STORE and what to SERVE — redirected responses, a login page cached as the shell, missing assets, and that no path can reject |
| Field Mode + worker | PHPUnit | `FieldModeTest` | The shell renders with no patient data; the worker's six rules |
| Device dashboard | PHPUnit | `DeviceDashboardTest` | Revocation with a reason; no payloads on screen |
| Capture workflows | Vitest | `tests/js/workflows.test.js` | What the device refuses at the bedside, and that it queues exactly one operation when it accepts |
| Resolving a conflict | Vitest + PHPUnit | `tests/js/conflicts.test.js`, `SyncPushTest` | Keep mine / keep theirs, and that the follow-up operation is one the server actually accepts |
| Where the app is served from | Vitest + PHPUnit | `tests/js/base-path.test.js`, `FieldModeTest` | Nothing assumes the origin root — the bug that made offline mode silently not work |
| **A whole shift, end to end** | PHPUnit | `FullShiftTest` | Pull the ward → work offline → lose a response → sync → both databases agree, nothing doubled |
| **The invariant register** | PHPUnit | `DataIntegrityInvariantsTest` | One test per invariant I-1…I-15, plus a guard that the plan and the register agree |
| How a browser authenticates | PHPUnit | `SyncSessionAuthTest` | That the api routes can see a session at all — the bug that made the whole feature inert. Asserts the MIDDLEWARE STACK, because `Sanctum::actingAs()` proves nothing about whether a cookie could reach it |
| The clinical narrative | PHPUnit + Vitest | `VisitClinicalSyncTest`, `workflows.test.js` | Every field never-auto-merged; the uncontested half still written; the narrative pulled only to somebody who could write one |

**Totals, actually executed:** **142 Vitest** · **158 PHPUnit offline** ·
**1,774 in the full suite** (6,707 assertions), up from 1,609 before this work,
with no regression.

---

## 3. The scenario matrix

Plan §19.2. Status is what was **actually run**, not what was intended.

| # | Scenario | Status | Where |
|---|---|---|---|
| 1 | Online → offline → online | PASS | `sync-engine.test.js` durability |
| 2 | Offline 5 minutes | PASS | Implicit in every engine test |
| 3 | Offline several hours | PASS | Backoff caps at 1h, `backoffFor` |
| 4 | Offline a full working day | PARTIAL | Backoff and expiry tested; a real 12-hour session is not |
| 5 | Network drops mid-sync | PASS | "puts a batch back when the server never answers" |
| 6 | Server dies mid-sync | PASS | Same path, `HttpError` 5xx |
| 7 | Internet up, server down | PASS | `connectivity.test.js` |
| 8 | **Server commits, response lost** | PASS | Both suites. The one that matters most |
| 9 | Browser crash with pending ops | PASS | `reopen()` after an ungraceful close |
| 10 | Device restart with pending ops | PASS | Same mechanism |
| 11 | Two devices edit one patient | PASS | `SyncPushTest` merge + conflict |
| 12 | Two tabs edit one patient | PASS | `withSyncLock` concurrency test |
| 13 | Same operation twice | PASS | `OperationLedgerTest`, ten replays |
| 14 | Batch of 500 | PASS | 220 through the engine; 200 cap refused at 250 |
| 15 | Thousands of pending operations | PARTIAL | 220 in-engine, 1,820 rows pulled on live MySQL; 10,000 not run |
| 16 | **SW update with 50 pending** | PARTIAL | Proven by construction (the worker has no DB code) + migration test carries 50 ops. A real browser SW update is BLOCKED |
| 17 | Local schema v1 → v3 | PASS | `migrations.test.js` |
| 18 | Quota exceeded | PARTIAL | Reported and warned; the browser refusing a write is not simulated |
| 19 | Device revoked while offline | PASS | `DEVICE_REVOKED` event + wipe path |
| 20 | Clock skewed by 3 hours | PASS | Capture blocked, demographics still allowed |

---

## 4. Failure injection

The fake server in `sync-engine.test.js` can be told to misbehave in ways a
real one cannot be asked to on demand:

```js
server.failNextPush = new TransportError('Could not reach the server.'); // dead network
server.failNextPush = new HttpError(401, null, 'Unauthenticated.');      // expired session
server.failNextPush = new HttpError(403, 'device_revoked', '…');         // blocked device
server.loseNextResponse = true;                                          // commit, then vanish
server.rejectEntities = new Set(['patients']);                           // server says no
server.conflictOn = someUuid;                                            // somebody got there first
```

Covered: transport failure · timeout · 401 · 403 · 409 · 422 · 5xx ·
unreadable body (a proxy's HTML error page with a 200) · lost response after
commit · duplicate submission · rejected parent with dependants · unknown
entity from a newer server.

---

## 5. Performance, measured

Against `OfflineDemoSeeder` on live MySQL — 1,200 patients, 120 open visits,
40 inpatients with charts:

| Measurement | Result |
|---|---|
| Seeding 1,200 patients **through `PatientService`** | ~9 s |
| Full pull, cold cursor | 1,820 rows in 10 pages, **1,062 ms** |
| Slowest page | 156 ms |
| Duplicates delivered | **0** |
| Rows left unrevisioned after backfill | **0** |
| 220 operations pushed in batches of 50 | 5 requests, no duplicates |

Not measured: 10,000+ patients; a device with 10,000 queued operations;
large attachment queues (attachments are not implemented — see §7).

---

## 6. Writing a new test

Server side, `tests/Feature/Offline/`:

```php
$this->push([$this->op('vitals', 'create', [
    'uuid' => (string) Str::uuid(),
    'admission_uuid' => $admission->uuid,
    'pulse' => 78,
])])->assertOk();
```

Client side, `tests/js/`:

```js
const db = await freshDb();
const { row } = await repoFor(db).create('patients', { first_name: 'Amina' });
const server = fakeServer();
server.loseNextResponse = true;          // the interesting part
await engineFor(db, server).sync({ pull: false });
```

**The rule:** a test that only proves the happy path proves very little here.
Every bug found during implementation was found by a test written to break
something — the list is in `OFFLINE_IMPLEMENTATION_PROGRESS.md`.

---

## 6b. Verified by hand against the running server

Everything in §2–§5 runs on SQLite with a fake transport. This is the other
thing: one full cycle driven with `curl` against MAMP on MySQL, signed in with
a real session cookie obtained from `/admin/login`, exactly as a browser holds
it. Reproduce it the same way; there is no script, on purpose — the point is
that nothing in it is mocked.

| Step | Result |
|---|---|
| `POST /admin/login` | `302` → `/admin` |
| `GET /field` | `200` |
| `POST /api/v1/sync/register` | `200`, device created |
| `POST …/register` with **no** `X-XSRF-TOKEN` | `419` — this is why the client reads the cookie per request |
| `GET /api/v1/sync/status` | `200`, `hospital_id: 1`, device echoed back |
| `POST /api/v1/sync/push` (a vitals round) | `accepted`, `server_id: 283`, `version: 1` |
| **the same `operation_id` again** | `already_processed`, **same `server_id`**, no second row |
| push as a user without the ability | `rejected: forbidden` — authorisation is live, not cached |
| push past the hospital's plan limit | `rejected: refused`, in the domain rule's own words |
| `GET /api/v1/sync/pull` | `200` — **105 changes** interleaved across `patients` (98), `visits` (4), `admissions` (2), `vitals` (1); `has_more: false`; 288-byte encrypted cursor |
| every field of every pulled record | **no balance, amount, price, card or bank field anywhere** (I-12, checked by pattern over all 105) |
| `POST /api/v1/sync/ack` | `200` — *after* bug 19; it was `500` on every call before |
| `GET /sync/pull` as a **doctor** | the visits record carries `complaints`, `diagnosis`, `doctor_remarks` |
| `GET /sync/pull` as a **receptionist** | the same visit, with none of those three fields |
| `POST /sync/push` a clinical note that also forges `stage` and `visit_no` | `accepted` — the note written, the forged fields silently DROPPED, the diagnosis somebody else wrote untouched, version 1 → 2 |

Signed out, the same `GET /sync/status` is `401` with `code: unauthenticated`,
which is the case the client turns into "You have been signed out — your work
is still here."

---

## 7. What has NOT been tested

Stated plainly rather than left to be discovered.

| Item | Status | Why |
|---|---|---|
| Real browser end-to-end (Playwright) | **NOT RUN** | No browser automation in this environment. The worker's rules are asserted against its source; its runtime behaviour is not exercised |
| A real service-worker update cycle | **NOT RUN** | Same. Invariant I-6 is held by construction — the worker contains no database code at all — which is stronger than a test, but is not the same as having watched it happen |
| Storage quota actually exhausting | **NOT RUN** | Needs a real browser under pressure |
| 10,000 pending operations | **NOT RUN** | 220 in-engine and 1,820 pulled were run |
| Attachments | **NOT BUILT** | Plan phase 10. Not implemented, so not tested |
| Offline PIN / Web Crypto wrapping | **WONT BUILD** | Plan phase 9, deliberately dropped: there is no stored token to wrap. See `OFFLINE_SECURITY.md` §3 |
| Field Mode's remaining three workflows | **NOT BUILT** | Register · correct details · vitals · nursing note · medication · lab results · clinical notes are built and tested end to end. Dispensing intents, appointment check-in and opening a visit need server handlers first |
| A conflict resolved in a real browser | **NOT RUN** | The resolver and the screen are tested; clicking the button in a browser is not |

Anything in this table is `BLOCKED` or `NOT_STARTED` in the progress document.
None of it is marked passed.

### What the first row has already cost

Two of the worst bugs in this feature lived exactly in that gap, and neither
made a single test fail:

- Every path was resolved from the origin root, so on an installation served
  from `/true-doctor/` the worker never registered and the API was unreachable.
- The API routes had no session middleware, so a signed-in browser got `401`
  on every sync request. **Offline mode had never synced once.**

Both were found by leaving PHPUnit — the first from a screenshot of the
browser's own error page, the second with `curl` and a real login cookie. The
tests written afterwards assert the middleware stack and the request headers
directly, because an integration test that authenticates with
`Sanctum::actingAs()` sets the user on the guard and therefore proves nothing
about whether a cookie could ever have reached it.

Until there is browser automation here, **a change to the request path, the
worker, or the authentication stack is not verified by this suite passing.**
Check it against the running server by hand.

### A test double can hide the only path production takes

The worst bug in this feature was `openOfflineDb` handing Dexie
`{indexedDB: undefined}`, which overwrote the browser global and made Dexie
report its own API as missing. **The local database had never opened in a
browser once.**

No test could see it, and the reason generalises: every test injects
fake-indexeddb *explicitly*, so the option always had a value and the fallback
to the global was never exercised. The injected double did not merely stand in
for the real thing — it routed around the only line production depended on.

So, when a seam exists for testing: **cover the call with the seam unused.**
`foundation.test.js` now opens the database with no options at all, which is
exactly how the application does it.

### The suite runs on SQLite, and SQLite forgives things MySQL does not

The same hand-check found `POST /sync/ack` returning **500 on every call**:
`devices.pull_cursor` was `varchar(255)` holding a 288-character encrypted
cursor. **SQLite does not enforce VARCHAR lengths at all** — it stores whatever
it is given — so the behavioural test passed, and would have passed for ever.

So: **a column width is not verified by this suite.** Nor is a constraint added
to a live table, for a different reason already written up in
`App\Support\SchemaKeys`. When a migration touches either, check it against
MySQL before believing it. Where a difference can be pinned, pin the schema
rather than the behaviour — `test_a_device_can_store_a_whole_pull_cursor`
asserts the column's *type*, which is the thing that actually differs.
