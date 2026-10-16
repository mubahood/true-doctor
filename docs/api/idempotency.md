# Idempotent writes (`Idempotency-Key`)

A write whose answer never arrived (timeout after the server committed) must be retryable without doing
the work twice. Send an `Idempotency-Key` header on a `POST`, `PUT`, `PATCH` or `DELETE`.

- Key: 1-100 characters from `A-Za-z0-9._:-` (a UUID is ideal). Anything else is a `422`.
- No header: nothing changes. The middleware (`idempotent`, `App\Http\Middleware\IdempotentRequests`) sits on
  the whole authenticated `/api/v1` group. `sync/push` (it has its own ledger) and `auth/login` are excluded.
- Scope: the key is per signed-in user; another user (or hospital) sending the same key is unaffected.
- First use: the request runs. The answer is stored for 24 hours if it is final: any `2xx` or `4xx`
  except `401`, `403`, `408`, `422`, `423`, `429`. `5xx`, validation `422`s and non-JSON answers (PDFs) are
  not stored, so the client may correct and resend under the same key.
- Same key, same method + path + query + body: the stored status and body are replayed, the controller does
  not run, and the response carries `Idempotent-Replay: true`. Body key order does not matter; for multipart
  uploads the file names and sizes are compared, not the bytes.
- Same key, different request: `422`, code `idempotency_key_reused`.
- Same key while the first request is still running: `409`, code `request_in_progress` (retry shortly).

Stored answers live in `idempotency_keys` (`user_id`, `key`, `request_hash`, `status`, `body`, `created_at`;
unique on user + key) and are pruned daily at 03:30. Limit: if the server dies between committing the work and
storing the answer, a retry runs again; that window is a few milliseconds.

The app (`ApiClient`) reuses a key only for an identical write that follows a "no answer" failure.
