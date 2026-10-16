# Account security in the API

Two-step sign-in (an authenticator-app code after the password) and the apps signed in to an account. The web page `/account/security` and these endpoints call the same `TwoFactorService` and `AccountSecurityService`.

## Signing in when two-step sign-in is on

1. `POST /api/v1/auth/login` with the email and password, as before. When the person has two-step sign-in on, the answer is **401** with `code: "two_factor_required"`:

   ```json
   { "success": false, "code": "two_factor_required", "message": "Enter the 6-digit code from your authenticator app.",
     "data": { "challenge": "<sealed>", "methods": ["totp", "recovery_code"] } }
   ```

   No token is issued. The challenge is sealed by the server and lasts 10 minutes.

2. `POST /api/v1/auth/two-factor` with `challenge`, `code` (the 6 digits, or a recovery code such as `ABCDE-FGH23`) and optionally `device_name`. The answer is the same as a normal sign-in (`token`, `user`, `password_change_required`) plus `recovery_codes_left`.

   - Wrong code: **422** `validation_failed` with `errors.code`.
   - Expired or forged challenge: **401** `unauthenticated` (sign in again).
   - Five wrong codes in a minute: **429** with `retry_after`.
   - A code that was already used is refused, and a recovery code works once.

## Owners and administrators must set it up

When the installation enforces it (`TWO_FACTOR_ENFORCE`, on by default) owners (`hospital_admin`) and the super admin must have two-step sign-in. Until they do, every endpoint except `auth/me`, `auth/logout`, `auth/password`, `meta`, `auth/two-factor/setup` and `auth/two-factor/confirm` answers **403** `two_factor_setup_required`. Demo accounts are never asked.

- `POST /api/v1/auth/two-factor/setup` gives `secret` (base32, for typing) and `uri` (`otpauth://…`, which an authenticator app on the same phone can open). Not in force yet.
- `POST /api/v1/auth/two-factor/confirm` with `code` turns it on and returns `recovery_codes` (8, shown once).

`GET /meta` carries `security`: `two_factor_enabled`, `two_factor_required`, `recovery_codes_left`.

## Devices and signing out everywhere

- `GET /api/v1/auth/devices`: the apps signed in (`id`, `name`, `last_used_at`, `created_at`, `current`).
- `DELETE /api/v1/auth/devices/{id}`: sign one out (404 when it is not this person's).
- `POST /api/v1/auth/sign-out-everywhere` with `password`: every other app and every web session end; the token making the request stays. Demo accounts cannot.

## Crash reports from the app

`POST /api/v1/diagnostics/errors` with `errors`: up to 20 items of `kind` (the error's type), `message`, `where` (file and line) and `app_version`. Scrub them on the phone first (the app's `Redactor`); the server scrubs them again and groups them with its own errors (Super → Errors). Answers the reference of each. Limited to 10 calls a minute.
