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

`GET /meta` carries `security`: `two_factor_enabled`, `two_factor_required`, `recovery_codes_left`, and `self_service: true` on a server that takes the two endpoints below (an app hides them when it is absent).

## Turning your own off, and new recovery codes

Both need the person's password, as the web's Account security page does, and allow 12 tries a minute each (a bucket of their own).

- `POST /api/v1/auth/two-factor/disable` with `password`: turns it off (the secret, the recovery codes and every trusted browser go). Answers `{two_factor_enabled: false, two_factor_required}`. When the role must use it (`two_factor_required: true`) this is the move to a new phone: from the next request the person is held on setting it up again (`two_factor_setup_required`), and `auth/two-factor/setup` starts it.
- `POST /api/v1/auth/two-factor/recovery-codes` with `password`: 8 new `recovery_codes`, shown once; the old ones stop working.
- No password: 422 `errors.password` "Enter your password to confirm."; a wrong one: "That is not your password.". Already off (or no codes to replace): 409 `conflict`. Demo accounts: 403.

## A colleague who lost their phone

`POST /api/v1/admin/users/{id}/reset-two-factor` turns a colleague's two-step sign-in off (the web's "Turn off two-step sign-in" on User accounts; docs/api/admin.md). `GET /api/v1/admin/users/{id}` says `two_factor_enabled` and `can_reset_two_factor`.

## Secrets are never kept for a replay

The answers that carry a secret (`setup`'s `secret`, `confirm`'s and `recovery-codes`' `recovery_codes`) are sent with `Cache-Control: no-store`, and the idempotency middleware never stores such an answer (docs/api/idempotency.md). A retry under the same `Idempotency-Key` runs again and returns a new secret or a new set of codes: the newest one shown is the one that works. A `confirm` sent again after it succeeded answers 409 "Two-step sign-in is already on. If you did not see your recovery codes, make new ones in My account."

## Devices and signing out everywhere

- `GET /api/v1/auth/devices`: the apps signed in (`id`, `name`, `last_used_at`, `created_at`, `current`).
- `DELETE /api/v1/auth/devices/{id}`: sign one out (404 when it is not this person's).
- `POST /api/v1/auth/sign-out-everywhere` with `password`: every other app and every web session end; the token making the request stays. Demo accounts cannot.

## Crash reports from the app

`POST /api/v1/diagnostics/errors` with `errors`: up to 20 items of `kind` (the error's type), `message`, `where` (file and line) and `app_version`. Scrub them on the phone first (the app's `Redactor`); the server scrubs them again and groups them with its own errors (Super → Errors). Answers the reference of each. Limited to 10 calls a minute.
