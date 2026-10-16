# 04 · Offline and the app

The Flutter app (`true-doctor-mobo`, Android, iOS, Windows/macOS) is **a client of this server**, not a copy of it. It shows the same screens, fields and statuses as the web, and it keeps working without a connection. Read this chapter before changing any endpoint the app calls, and read the app's own `AGENTS.md` before changing the app.

## What "offline" means here (three tiers)

1. **Synced entities** (patients, visits, vitals, notes, lab results, ward charts, appointments queue…). Stored in the device's SQLite, edited offline, pushed through the **outbox** and `/api/v1/sync/*`, merged by a documented protocol with conflict detection. Spec: [`../OFFLINE_SYNC_PROTOCOL.md`](../OFFLINE_SYNC_PROTOCOL.md), design: [`../OFFLINE_ARCHITECTURE.md`](../OFFLINE_ARCHITECTURE.md), threat model: [`../OFFLINE_SECURITY.md`](../OFFLINE_SECURITY.md).
2. **Read-through copies** (the queue, the diary, wards, stock, invoices, registers, the patient record summary, dashboard, insights). `ReadCache.through(key, fetch)` returns fresh data when online and the last copy when not, tagged with when it was fetched; screens show "as of …". Keys are per user/hospital.
3. **Online-only actions** (take a payment, dispense, issue a card, change settings…). They say so and stay disabled/explained offline. They never *fail silently*.

Never move a thing from tier 3 to tier 1 casually: money and stock need the server's lock to be safe.

## Server rules that protect the phone

- **Every write endpoint must be safe to retry.** The app re-sends after a no-answer failure with the *same* `Idempotency-Key`; the middleware replays. If you add a write outside the API group's idempotency cover, you have broken a promise. Test replay.
- **Sync contracts are versioned.** Don't change the shape of `/sync/*` in place; add fields compatibly, bump protocol only with a plan. Old installed apps exist for months.
- **IDs are UUIDs** generated on the device for new records (`lib/sync/ids.dart`); the server accepts and keeps them.
- **Clocks lie.** The device measures its offset from server time (`DeviceClock`) and warns when unknown. The server remains the authority for ordering; never trust a client timestamp for money.
- **Pull must tell about deletes** (tombstones). Known gap: closed lab orders vanish from the worklist without a tombstone. If you touch the worklist, fix it.
- **Pagination is capped.** Say so in the UI when a list is a slice ("50 of 212"); the radiology list still lacks it.
- **Rate limit** 240/min; sign-in fans out ~30 requests. Batch where you can; GETs retry on 429/502/503/504 honouring `Retry-After`.
- Responses the app caches must be **stable and small**. Don't embed huge blobs or per-request noise (it breaks cache diffing and fills the 6 MB cache budget).

## What the app guarantees (don't break these from the server side)

- Typed data is never discarded without consent; a failed save keeps the form.
- A rejected change is shown with the server's reasons against the field; it does not retry forever.
- Conflicts are shown to a person with *keep mine / keep theirs*; a newer pull never silently overwrites a manual conflict.
- Unsent work is never trimmed by storage clean-up.
- A PIN lock (2 min away, 5 tries then sign-out, per person) sits above every open screen; the app hides its content in the task switcher; Android blocks screenshots in release builds; backups are excluded.

## Adding or changing an endpoint the app uses — checklist

1. Service first, then controller, Resource, route (under the right `routes/api/*.php`), Policy.
2. Envelope via `ApiResponse`; uuids, not ids; money as strings.
3. Hide sections the user may not see (omit, don't empty).
4. Idempotent if it writes; test the replay.
5. Document in `docs/api/*.md` (request, response sample, errors).
6. Feature test in `tests/Feature/Api`, including: 401 without token, 403 without permission, 404 for another hospital's record, validation envelope.
7. Tell the app side what to build (cache key, offline behaviour, tier). Do the app change in the same piece of work when asked for "the feature", not only the endpoint.
8. Run a call against the **local server with real data** and compare the real JSON to what the app parses. Shape mismatches (`insights` as object vs list) were found only this way.

## Editions and the app

The app signs in to the cloud **or** to a hospital's own server (the URL is checked before use). The server reports `edition`, brand (logo, colour, tagline), subscription state and the practice kind; the app themes and words itself from that. Don't hard-code "True-Doctor" or "Pharmacy" in the app: read it from meta.

## Releases of the app

Android release signing key lives outside the repos in `~/.true-doctor-signing/` (back it up; every update must be signed by it). Details in the app repo README. CI for all repos is currently blocked by a GitHub billing issue, so tests are run locally. Push notifications wait on a Firebase project.
