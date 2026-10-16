# The demo door (app)

The mobile app's "Try the demo": the same demonstration hospital the web offers at `/test-login`
(`docs/demo-accounts.md`), but the app never holds or types the public demo password. The server
signs the visitor in as a **role**. SaaS edition only: both routes are 404 on a standalone
installation. Both are public (no token).

Envelope, errors and throttling are as in `docs/api.md`.

## GET /api/v1/public/demo

30 per minute. Answers `200` always. When there is no demonstration (the demo switch is off, the
demo hospital is missing, or it has no usable staff) it is `enabled:false` with no roles.

```json
{
  "success": true,
  "data": {
    "enabled": true,
    "hospital": { "name": "General Hospital A", "currency": "USD" },
    "resets": false,
    "roles": [
      {
        "key": "receptionist",
        "label": "Receptionist",
        "icon": "fa-id-badge",
        "headline": "Welcome patients and take payment.",
        "can": ["Register a new patient", "Check patients in", "Issue and settle an invoice"],
        "minutes": 5,
        "missions": [
          { "id": "reception-register", "title": "Register a patient", "hint": "Add a new person to the register.", "route": "admin.patients.index" },
          { "id": "reception-checkin", "title": "Check someone in", "hint": "Open the check-in queue and mark an arrival.", "route": "admin.appointments.queue" },
          { "id": "reception-invoice", "title": "Take a payment", "hint": "Open an invoice and record a payment.", "route": "admin.invoices.index" },
          { "id": "reception-offline", "title": "Offline challenge", "hint": "Switch on airplane mode, register a patient, switch it back on. It sends itself.", "route": "admin.patients.index" }
        ]
      }
    ]
  }
}
```

Disabled:

```json
{ "success": true, "data": { "enabled": false, "hospital": null, "resets": false, "roles": [] } }
```

- A role is listed only when a real, active, seeded staff account of that role exists in the demo
  hospital. Roles are `doctor, nurse, receptionist, pharmacist, lab_technician, radiologist,
  accountant, records_officer, hospital_admin`. The platform super admin is never offered.
- `route` is a **web route name** (as in `meta.navigation[].route`) that the role's menu contains;
  the app maps it to a native screen or opens it on the web. A test walks the catalogue
  (`App\Support\DemoPersonas`) against `Navigation` so a mission is never a dead end.
- `resets` says whether the demonstration is wiped nightly.
- The response never contains an email or a password.

## POST /api/v1/public/demo/login

12 per minute per IP. Body: `{ "role": "receptionist", "device_name": "Pixel 8" }` (`device_name`
optional). The client never sends an email or an id; one is ignored if sent.

```json
{
  "success": true,
  "message": "Signed in to the demonstration.",
  "data": {
    "token": "123|abc...",
    "user": { "id": 5, "name": "Rita (Receptionist)", "email": "reception.a@test.com", "role": "receptionist",
              "hospital_id": 2, "hospital": { "id": 2, "name": "General Hospital A" },
              "is_super_admin": false, "permissions": ["patients.view", "..."] },
    "password_change_required": false,
    "demo": { "role": "receptionist", "role_label": "Receptionist", "hospital": "General Hospital A" }
  }
}
```

- `404 not_found` when there is no demonstration. `422 validation_failed` (`errors.role`) for an
  unknown role or a role nobody holds. `429` after the throttle.
- The account is the first (lowest id) **active** `@test.com` staff member of that role in the demo
  hospital. Platform accounts, accounts without a hospital and other hospitals' staff cannot be
  reached by any role key.
- The token is named `demo-app:<device_name>` and **expires after 24 hours** (`401` afterwards;
  sign in again). Expired demo tokens of the account are deleted on the next demo sign-in.
- Nothing about the account changes; `password_change_required` is always `false` in the response.

## meta.demo

`GET /meta` carries `demo` only for a user of the demo hospital (web or app sign-in alike):

```json
"demo": { "active": true, "role": "receptionist", "role_label": "Receptionist", "hospital": "General Hospital A", "resets": false }
```

For everyone else the key is absent. `capabilities` is unchanged.

## Guard rails for demo users

The demo passwords are public, so a demo user cannot lock the next visitor out:

- `POST /auth/password` answers `403 forbidden`, "Demonstration accounts cannot change their password."
- `POST /admin/users/{id}/reset-password`, and `PUT|PATCH|POST /admin/users/{id}` when the request
  changes the password, the email or the role, answer `403 forbidden`, "Demonstration accounts
  cannot change their password, email or role."
