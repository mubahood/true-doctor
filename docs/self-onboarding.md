# Self-registration, plan payment & onboarding (Step 21)

The public sign-up → trial → pay → guided setup flow.

## 1. Self-registration + trial

`GET /register` (public) shows the sign-up form with the active **plans** (seeded by
`PlanSeeder`: Starter / Professional / Enterprise, each with `limits`). `POST /register`
(`RegistrationService`) creates, in one transaction: the **Hospital**, its **owner**
(`hospital_admin`), and a **14-day trial** `Subscription` (status `trialing`) on the chosen
plan — then signs the owner in and drops them at onboarding. The trial grants full access until
`trial_ends_at` (EnsureSubscribed allows `trialing`); after that, access needs a paid plan.

## 2. Onboarding wizard

`GET /admin/onboarding` (`OnboardingController`) is a checklist whose steps **auto-complete as
the data appears** — configure billing, add a price list, create a department, invite staff,
activate the subscription — each deep-linking to its setup page, with a progress bar. No
separate state is stored; it reflects reality.

## 3. Pay the plan (Flutterwave)

`GET /admin/subscription` shows the current plan/trial status and the plan grid. `POST
/admin/subscription/{plan}/checkout` (`SubscriptionCheckoutService::initialize`) opens a
Flutterwave hosted payment for the plan price and logs it as a **subscription-purpose**
`gateway_log`, then redirects the owner to pay. The **same** verified, idempotent
`GatewayPaymentService::settle` (callback + signed webhook) handles it: it branches on the log's
purpose and calls `SubscriptionCheckoutService::activate`, which converts the trial to an
**active paid period** (`status=active`, `ends_at = now + cycle`, `trial_ends_at` cleared) and
records a `SubscriptionPayment`. Plan-limit enforcement (§21) then applies to the active plan.

## Tests

`RegistrationTest` (plans listed, signup creates hospital+owner+trial and logs in, duplicate
email rejected, onboarding shown); `SubscriptionCheckoutTest` (checkout logs a subscription
gateway payment + redirects; settle activates the subscription + records a payment). All via
`Http::fake` — never the live gateway.

## Subscription emails

Queued notifications (mail + in-app database) to the hospital owner:

- **WelcomeToTrial** — on self-registration (trial start + link to onboarding).
- **SubscriptionActivated** — on paid activation (receipt: plan, amount, active-until), sent to
  the hospital's admins from `SubscriptionCheckoutService::activate` (webhook has no session).
- **TrialEnding** — dispatched by `subscriptions:trial-reminders` (scheduled daily 08:00) for
  trials ending within N days (default 3).

All `ShouldQueue`; in dev the mail driver logs. Tested with `Notification::fake()`
(`SubscriptionEmailTest`): welcome on signup, receipt on activation, reminder in-window, and no
reminder for trials ending far out.

## Public-side integration

The signup → pay → onboard flow is surfaced across the marketing site:

- **Header** (every marketing page): "Sign in" + a primary **Start free trial** button → `/register`.
- **Mobile menu** and **footer** ("Get started" column) carry the same CTAs.
- **Home hero** — both CTAs now lead with **Start free trial** → `/register`.
- **Pricing** (`/pricing`) renders the **real active plans** from the DB (name, price, and the
  actual `limits` as staff/patients/beds), each card's CTA deep-linking to
  `/register?plan={id}` so the chosen plan is **preselected** on the signup form. Every plan
  advertises the 14-day free trial.

From there the flow is unchanged: register (trial + welcome email) → onboarding wizard → pay a
plan via Flutterwave (receipt email) → active subscription with plan-limit enforcement.

Tested by `MarketingSignupTest` (pricing lists real plans with trial CTAs, home/header link to
registration, `?plan=` preselects).
