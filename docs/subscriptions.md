# Subscriptions and payment

A hospital pays the platform for a plan. Plans are managed centrally by the
super-admin; a hospital owner sees them on `/admin/subscription`, pays through
Pesapal, and is blocked from the rest of the system if the subscription lapses.

## Money: priced, quoted and charged in UGX

Plans are priced **per month in UGX** — the currency Pesapal settles in — so
the number on the plan card is exactly the number that leaves the hospital's
account. No conversion sits anywhere on the money path, and there is no
rounding drift between the quote and the charge.

The `$` figure shown beside a price is **presentation only**: the UGX amount
divided by a fixed platform rate (`USD_TO_UGX_RATE`, default 3600), a stable
reference for anyone reading in dollars. It is never billed and never stored.

`App\Support\PlatformCurrency` owns all of this (bcmath, never float) — and is
deliberately separate from `App\Support\HospitalSettings`, which formats a
*patient's* bill in the hospital's own currency. The two never mix.

A hospital buys a **whole number of months** at checkout (1, 3, 6, 12 or 24).
`PlatformCurrency::forMonths()` is the only arithmetic on the money path.

What is stored where, for a subscription payment:

| Record | Amount | Why |
|---|---|---|
| `gateway_logs.amount` / `.currency` | the UGX total (months × monthly price) | `GatewayPaymentService::settle()` compares these against what Pesapal confirms, so they must be exactly what the gateway processed |
| `gateway_logs.meta.months` / `.monthly_price` | what was bought, and at what rate | lets `activate()` extend by exactly the period paid for, and explains the total |
| `subscription_payments.amount` / `.notes` | the UGX total, and "3 months" | the receipt and history line |

### Renewing, and converting from a trial

Renewing the **same plan** while a paid period is still running adds the new
months to the **end** of it — time already paid for is never thrown away.
Converting from a **trial** starts the paid period **today**: a trial is not
paid time, so its unused days are not handed out on top of the purchase.

## Plans are the super-admin's to shape

`/super/plans` (`App\Livewire\Super\Plans\Index`) edits everything about how a
plan is sold, not just what it costs:

- **price** (UGX per month) and **billing cycle**;
- **caps** — `max_staff`, `max_patients`, `max_beds` in the `limits` JSON,
  exactly the keys `App\Support\PlanLimit` enforces;
- **tagline** (`description`) and a dynamic list of **features** — free-text
  bullets shown on the plan card, so marketing copy needs no deploy;
- **is_featured** — puts the "Most popular" ribbon on exactly one plan;
- **is_active** — an inactive plan disappears from checkout, and
  `SubscriptionCheckoutController` refuses it even by direct URL.

A plan with no features falls back to rendering its caps as bullets, so old
plans still look complete.

## The subscription page

`App\Livewire\Subscription\Index` shows, in order:

1. **Status** — plan, state, and a plain-language line: days remaining while
   healthy, "Your subscription ended … Renew below to get back in." once it is
   not. The panel turns red and the icon changes; nothing depends on colour
   alone.
2. **Plans** — the point of the page, so they come first: the UGX price, its
   USD reference, what you can pay with (MTN, Airtel, Visa, Mastercard — the
   `x-ui.pay-methods` component), the feature list, the "Most popular" ribbon,
   and a button reading Subscribe / Renew / Extend this plan.
3. **Payment history** — reference, so it comes after the plans. Shown by
   default whenever a subscription exists, even with no payments yet.

A **trial is not a current plan**: a hospital on trial has bought nothing, so
no card is marked as current and every plan stays buyable.

Subscribing opens a **two-step wizard**: choose how many months (the total and
the date it covers you to update as you click), then confirm the billing
contact and pay. Only the final step is a real `<form method="post">` — that
one legitimately leaves the SPA for Pesapal's hosted page, and
`SpaNavigationHtmlTest` allow-lists exactly that route for exactly that
reason.

## The header badge

Every admin page carries a badge in the topbar (`x-shell.subscription-badge`)
saying where the hospital stands, because a subscription that lapses quietly is
a system that stops working without warning:

| State | Badge | Tone |
|---|---|---|
| never subscribed | "Subscribe now" | blue, urgent |
| trial, more than a week left | "Trial · 14 days left" | amber |
| trial, last week | "Trial ends in 3 days" | red, urgent |
| trial over | "Trial ended" | red, urgent |
| paid, comfortable | "60 days left" | green |
| paid, 14 days or less | "Renew · 9 days left" | amber, urgent |
| lapsed | "Subscription ended" | red, urgent |

Each carries a one-line reason on hover and for screen readers — the nudge, not
just the number. It links to the subscription page, and is shown **only** to
users with `manage-settings`: nobody else can act on it, so for them it would
be nagging about something they cannot fix.

`App\Support\SubscriptionState` answers all of this, and is the same object the
gate and the subscription page read — the badge cannot promise something the
gate would refuse on the next click. `App\Support\Subscription\Badge` is the
small value object it returns (label, tone, nudge, urgency).

## Pesapal (API 3.0)

`App\Services\Gateway\PesapalGateway` implements the same `PaymentGateway`
interface Flutterwave does, so nothing downstream knows the difference. Three
things make it shaped differently:

- **Auth** is a bearer token from `POST /api/Auth/RequestToken`, valid ~5
  minutes — cached for 4.
- **IPN registration** is a prerequisite: `POST /api/URLSetup/RegisterIPN`
  returns an `ipn_id`, which is the mandatory `notification_id` on every order.
  Registered once and cached for a month.
- **There is no webhook signature.** Pesapal's callback and IPN carry only an
  `OrderTrackingId` and no trustworthy status — by design. The only
  trustworthy status comes from calling `GetTransactionStatus` ourselves with
  our own token, which is exactly what `verify()` does and what
  `GatewayPaymentService::settle()` always does before money moves. So
  `verifyWebhookSignature()` has nothing left to check and returns true.

Two public routes, both GET:

| Route | Purpose |
|---|---|
| `gateway/pesapal/callback` | the browser coming back from Pesapal — settles, then shows a receipt page |
| `gateway/pesapal/ipn` | server-to-server notification — settles, then replies with the exact `{orderNotificationType, orderTrackingId, orderMerchantReference, status}` acknowledgement Pesapal expects, or it retries forever |

Both funnel into the same idempotent `settle()`, so the browser returning and
the IPN arriving cannot double-credit: the `gateway_logs` row is locked and
marked successful exactly once.

**Which gateway is used where.** Flutterwave remains the default binding for
patient invoice payments (any hospital's own currency); subscriptions use
Pesapal. That is a *contextual* binding on `SubscriptionCheckoutService`.
Note that a contextual binding matches whichever class the container is
directly building at that moment — it does not reach through a shared class's
nested dependencies — so `PesapalPaymentController` builds its own
`GatewayPaymentService` by hand rather than relying on one, because that
service is shared with the Flutterwave flow.

## Lapsing, and getting back in

`App\Http\Middleware\EnsureSubscribed` blocks tenant routes once no
access-granting subscription is left, after `tenancy.subscription_grace_days`.
It is deliberately narrow, the same way `RequireOnboarding` is:

- `admin.subscription.*` and `admin.logout` always pass — **a hospital that
  cannot pay must still be able to reach the page that lets it pay.** (Before
  this, the gate `abort(403)`-ed on every route including the subscription page
  itself, so a lapsed hospital was locked out of its own renewal.)
- an admin who can fix it (`manage-settings`) is **redirected** to the
  subscription page with the reason in a flash message, not given a raw 403;
- anyone else gets `errors/subscription-lapsed`, an on-brand page that explains
  the situation and offers sign-out — they cannot fix it, so they are not sent
  round a loop;
- JSON/API/Livewire requests still get a plain 403.

`SUBSCRIPTION_GATE=false` disables the gate (tests ship with it off;
`SubscriptionGateTest` switches it on explicitly).

## Configuration

```
PESAPAL_CONSUMER_KEY=…
PESAPAL_CONSUMER_SECRET=…
PESAPAL_ENVIRONMENT=sandbox          # sandbox | production
PESAPAL_IPN_URL="${APP_URL}/gateway/pesapal/ipn"
PESAPAL_CALLBACK_URL="${APP_URL}/gateway/pesapal/callback"
USD_TO_UGX_RATE=3600
```

The IPN and callback URLs follow `APP_URL` so they are right in every
environment without being re-edited.

**Local development:** Pesapal's servers cannot reach `localhost`, so the IPN
will never fire against a local install. This is not a problem for testing the
flow — the browser callback settles the payment on its own, and the IPN is the
durability net for production (a payer who closes the tab before redirecting
back). Nothing depends on the IPN alone.
