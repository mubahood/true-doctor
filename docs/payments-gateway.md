# Gateway payments — Flutterwave (Phase 4, Step 16)

Online payments (card, mobile money, bank, USSD) via Flutterwave, behind a swappable
`PaymentGateway` interface. Money is **only ever recorded after a server-side verify** — a
client redirect's `status` is never trusted.

## Secrets (C11)

All keys live **only** in `.env` (git-ignored) and are read through
`config('services.flutterwave')`. `.env.example` carries **placeholders only**; no real key is
in any tracked file. Set:

```
FLW_SECRET_KEY=      FLW_PUBLIC_KEY=      FLW_ENCRYPTION_KEY=
FLW_SECRET_HASH=     FLW_BASE_URL=https://api.flutterwave.com
FLW_CURRENCY=UGX     FLW_PAYMENT_OPTIONS=card,mobilemoneyuganda,banktransfer,ussd    FLW_TIMEOUT=20
```

`FLW_SECRET_HASH` is the value you also set as the webhook "Secret hash" in the Flutterwave
dashboard — inbound webhooks are authenticated by comparing it (constant-time) to the
`verif-hash` header.

## Files

| Concern | File |
|---|---|
| Interface + DTOs | `app/Services/Gateway/PaymentGateway.php`, `GatewayCharge.php`, `GatewayVerification.php` |
| Adapter | `app/Services/Gateway/FlutterwaveGateway.php` (v3 API; bound in AppServiceProvider) |
| Orchestration | `app/Services/Gateway/GatewayPaymentService.php` |
| Ledger | `gateway_logs` table + `app/Models/GatewayLog.php` |
| Controller | `app/Http/Controllers/Admin/GatewayPaymentController.php` |
| Config | `config/services.php` → `flutterwave` |

## Flow

1. **Start** (`POST admin/invoices/{invoice}/pay/flutterwave`, `billing.manage`): opens a hosted
   session for the invoice balance, writes a `pending` `gateway_log` keyed by our `tx_ref`
   (`TD-<invoice-uuid>-<nonce>`), and redirects the payer to Flutterwave's link.
2. **Return**: both the browser **callback** (`GET gateway/flutterwave/callback`, public) and the
   server-to-server **webhook** (`POST gateway/flutterwave/webhook`, public, CSRF-exempt,
   `verif-hash`-authenticated) funnel into one method:
3. **settle()** — the single place money moves. It `verify()`s the transaction server-side, then
   inside a transaction locks the `gateway_log` row and:
   - skips if already `successful` (**idempotent** — callback + webhook race safely),
   - rejects a **currency mismatch** or **underpayment**,
   - sets the tenant context from the log (a webhook has no session) so the `Payment`'s
     `hospital_id` auto-fills,
   - records the payment via `BillingService::recordPayment` with method `flutterwave` (never the
     prepaid-card path), capped at the outstanding balance,
   - marks the log `successful` + `verified` and links the `payment_id`.

`PaymentMethod::Flutterwave` was added specifically so a gateway card payment is never confused
with a prepaid-card debit.

## Tests

`FlutterwaveGatewayTest` (adapter — request shape, response parsing, webhook signature, all via
`Http::fake`); `GatewayPaymentTest` (settle records once + idempotent, currency-mismatch and
underpayment rejected, webhook bad-signature → 401, webhook happy path, start redirects to the
link). No test ever touches the live API.
