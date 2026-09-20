# Offline security

What holding patient data on a device actually means here, what protects it,
and — as importantly — what does not.

Written for whoever has to sign this off, and for the hospital administrator
who will be asked "is it safe to turn this on?".

---

## 1. The honest summary

Offline mode puts a **copy of patient records in a browser**. That is the whole
feature; there is no version of it where the data stays on the server.

So the question is not "is the data protected" in the absolute — a browser is
not a smartcard and this document will not pretend otherwise. The question is:
**how much data, for how long, who can take it away, and what is the blast
radius if a machine is lost?**

| | |
|---|---|
| How much | The last 30 days of patients, today's visits, current inpatients and their charts. **No invoices, no payments, no card balances, no documents unless pinned.** |
| For how long | A 12-hour capture session; the copy is cleared on sign-out |
| Who can take it away | Any administrator, from `/admin/offline-devices`, with a reason |
| Blast radius | One clinician's working set for one hospital, on one machine |

**The controls that actually matter operationally are the narrow window, the
short session, and remote revocation.** The cryptography raises the cost for an
opportunist; it does not defeat somebody with the unlocked device and time.

---

## 2. What is on the device

| Store | Contents | Why it is there |
|---|---|---|
| `patients` | Demographics, allergies, emergency contact | You cannot record a vitals round against a patient you cannot name |
| `visits`, `admissions` | Which stay, which bed, what for | Same |
| `vitals`, `nursing_notes`, `med_administrations` | Bedside records captured or pulled | The workflows offline mode exists for |
| `outbox` | Operations not yet sent, with their payloads | This is the user's unsent work |
| `ref_*` | Wards, beds, services, lab tests, medication **names** | To fill a form |
| `session` | Device id, permission snapshot, expiry | |

**Not on the device, deliberately:** invoices · payments · card numbers or
balances · stock quantities · insurance claims · bank details · patient
documents and scans · anything belonging to another hospital · anything
belonging to a clinician who is not signed in.

`Patient.bank_details` is encrypted at rest on the server and is excluded from
every sync payload. `card_number` never leaves the server in any form.

---

## 3. Authentication

```
Online login (session, unchanged)
        ↓
Explicit "set this device up for offline work"  ← named, audited, revocable
        ↓
The SAME session cookie authenticates every sync request
        ↓
Session ends → the queue waits, and says so. Nothing is lost.
```

**There is no credential on the device.** Field Mode is a browser on the same
origin, signed in the same way as the rest of the panel, and it authenticates
with the ordinary session cookie. `$middleware->statefulApi()` is what lets the
API routes see that session; `credentials: 'same-origin'` plus the `X-XSRF-TOKEN`
header is the client's half.

- **No password, and no password hash, is ever stored on a device.** Not
  hashed, not wrapped, not encrypted. Not at all.
- **No token either.** The session cookie is `HttpOnly`: no script on this
  origin can read it, so an XSS cannot exfiltrate it and there is nothing in
  IndexedDB for the next person at a shared workstation to find. A personal
  access token would have been the opposite — script-readable, and alive on the
  machine for its full 30-day expiry.
- The CSRF token is read from the `XSRF-TOKEN` cookie **at request time**, never
  from the page. The shell is served from the service-worker cache, so a token
  baked into the markup would be days old; a stale token is refused exactly like
  a missing one.
- **Session expiry gates new sending, not existing data.** A 401 or a 419 puts
  every operation back to `pending`, raises `AUTH_EXPIRED`, and shows "You have
  been signed out — your work is still here." Losing a shift's notes because a
  session lapsed would be the worst possible failure in this system.

### Why not a PIN

The plan's phase 9 proposed unlocking a stored token with a PIN wrapped through
Web Crypto. It is not built, and it should not be: there is no stored token to
wrap. Wrapping a long-lived credential with four digits, on a machine whose
storage the attacker already has, buys very little — and it would have meant
putting a credential on the device in order to have something to protect.

### Stated limitation

Browser storage is not a secure enclave. A person with an unlocked device and
developer tools can read the **clinical copy** — the patients, charts and
pending operations in IndexedDB. That is the residual risk of offline mode and
it is why device registration is named, audited and revocable, and why the
copy is bounded to 30 days of patients and the current inpatients. What they
cannot read is the credential.

---

## 4. Authorisation

**The client is never a security boundary.** The permission snapshot on a device
decides what the UI offers. The server decides what happens.

- Every operation is authorised **individually**, on arrival, against the live
  permission set — not once per batch (invariant I-15).
- A user whose permission was removed while their device was offline is refused
  now, on every operation, with a message saying so.
- Offline handlers use the **same abilities** as the online panels —
  `patients.create`, `visits.vitals`, `ipd.manage`. Inventing an offline-only
  permission would mean a role that can do something on a tablet and not at a
  desk, with nothing to say which was intended.
- Pull streams are permission-scoped: a receptionist's device receives no
  inpatient charts at all. The smallest defensible copy is the one that is not
  there.

---

## 5. Tenancy

- The hospital comes **from the authenticated user**, never from the payload. A device that
  sent `hospital_id` would be telling the server which tenant to write into;
  there is a test for exactly that.
- One local database per `(hospital, user)`. A shared workstation cannot leak
  one clinician's cache into the next person's session.
- Cursors are encrypted and bound to `(hospital, device)`. One issued elsewhere
  reads as "start again" rather than being explained.
- A cross-tenant `operation_id` replay is refused and the other hospital's
  result is **not** returned — the reply carries no `server_id`, so it cannot
  even be used to probe whether an id exists.

---

## 6. Revocation

From `/admin/offline-devices`, any administrator with `manage-users` can block
a device. A reason is **required**, because the device shows it to whoever next
opens it, and "this device has been blocked" with no explanation is how a
clinician decides the system is broken and starts writing on paper.

```
Block  →  next contact is refused with `device_revoked`
       →  the device wipes its local database, unsent work included
       →  the reason is shown to whoever opens it next
```

**This is a control, not a guarantee.** A machine that never reaches the server
again keeps what it has. Revocation stops future syncing and clears the copy on
contact; it cannot reach into a laptop at the bottom of a lake. Hospitals
should treat an offline-enabled device the way they treat a paper ward file.

---

## 7. Replay, tampering and duplication

| Attack | Defence |
|---|---|
| Replaying a captured push | `operation_id` unique index. The replay returns the original result and creates nothing (I-1, I-2) |
| Same id, different payload | The first result stands; the mismatch is flagged via a payload hash |
| Forged cursor | Encrypted and bound to hospital and device |
| Operation older than 90 days | Refused rather than applied blind — the ledger has been pruned and a replay can no longer be recognised as one |
| Claiming another tenant | Hospital comes from the authenticated user |
| Impersonating a device | The id must exist, belong to this hospital, and not be revoked |
| Inventing a patient number | Server-allocated under a row lock; a device-supplied one is discarded (I-11) |
| Inventing a balance | No balance is computed on or accepted from a device (I-12) |

---

## 8. What is logged, and what is not

The `sync_operations` ledger stores **a SHA-256 of the payload, never the
payload**. A clinical record sitting in a log table is a second copy under
nobody's governance — readable by whoever can see a diagnostics page rather
than by whoever can see the patient.

The same rule holds on the device: the diagnostic ring buffer carries event
type, entity type and uuid. No field values, ever. There is a test that pushes
a patient called "Confidential" and asserts the trail does not contain it.

Conflict records store **field names**, not field values.

`/admin/offline-devices` shows what a device did — operations, statuses,
reasons, timings — and says out loud that what was in them is not stored.

---

## 9. XSS

XSS is the real risk to IndexedDB: script running on the origin can read
everything the page can read, and no amount of at-rest encryption helps once
the key is in the same context.

- Field Mode renders **no unescaped HTML**. Every value goes through Blade
  escaping or Alpine `x-text`; there is no `x-html` anywhere in it.
- The shell carries no user-supplied content at all — two integers and a name.
- The service worker never caches an API response, so a stolen cache entry is
  not a route to patient data.

---

## 10. Withdrawing the feature

Four independent levers, in increasing order of severity:

1. `config('offline.enabled') = false` — hides the entry point. **Local data and
   outboxes are untouched**, and a device with pending work can still sync it.
2. `UNREGISTER = true` in `public/field-sw.js` — the worker unregisters and
   clears its caches on next load. Does not touch IndexedDB.
3. Revoke devices individually — each wipes on contact, with a reason.
4. Remove the sync routes — additive, so nothing else is affected.

**No step in this path deletes unsent work**, which is what makes it safe to
pilot in one ward.

---

## 11. Sign-off checklist

- [x] No password or password hash stored on any device
- [x] No token on any device either — the session cookie is HttpOnly
- [x] Data minimised: no money, no documents, no other tenants
- [x] Per-operation server-side authorisation
- [x] Tenancy from the authenticated session, never the payload
- [x] Revocation with a reason, and a local wipe on contact
- [x] Replay-safe by unique index, not by application logic
- [x] Payloads never logged — hashes only
- [x] Conflict records hold field names, not values
- [x] The client is never treated as trusted
- [x] Limitations stated rather than implied
