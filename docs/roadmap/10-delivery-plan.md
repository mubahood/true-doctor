# 10. Delivery plan

The order of work, what counts as done, how we measure it, and the decisions only the owner can make.

## 1. Phases

Each phase ends with a short report:

- what shipped, with screenshots;
- what tests were added;
- what was learned;
- what changed in the plan.

A phase is not started until the one before has its P0 items done.

### Phase 0: trust and contact (weeks 1 to 2)

| Item | File |
|---|---|
| `SupportContact` and the WhatsApp number on every P0 surface: site, sign-in, back office, emails, errors, lapsed page, API meta, app | 03 |
| A real contact email; `MAIL_ENQUIRIES_TO` set | 02 §8 |
| Demo on in production, with the `/demo` door and role cards | 02 §6 |
| WhatsApp number required at sign-up | 02 §6 |
| Docs made true (`CAPABILITIES.md`, `HMS_PLAN.md`, exchange rate, Pesapal) | 07 §7 |
| Scheduler that cannot miss a job; bed charges caught up | 07 §1 |
| SaaS backups and the first restore drill | 07 §1 |
| `td:check`, `/health`, error tracking, uptime monitor and status page | 07 §1 |
| Passwords to 8 characters plus the breached check; 2FA for owners and administrators; encrypted sessions | 07 §2 |
| CI unblocked and sharded | 07 §1 |
| App: one app ID, version from pubspec, commits pushed, help card, Play internal testing | 08 §1, §2 |
| Allergy and alert banner on clinical screens | 04 §10 |

### Phase 1: the selling engine and the dental client (weeks 3 to 6)

| Item | File |
|---|---|
| Home page rewrite, real screenshots, inline icons, self-hosted Inter, 200 KB budget | 02 §3, §4 |
| Technical SEO: robots, sitemap index, per-page OG images, JSON-LD set, noindex rules | 02 §3 |
| `/for/dental-clinics`, `/for/private-clinics`, `/for/maternity-centres`, `/for/hospitals`, `/walkthrough`, `/get-app`, `/about` | 02 §2 |
| Pricing page with per-day price, add-ons and the one-off copy (after the owner's price decision) | 02 §5 |
| Funnel events and the `/go/whatsapp` click tracking in `/super/traffic` | 02 §6, 03 §4 |
| Live SMS through Africa's Talking, consent records, day-before reminder live, receipt message | 05 §1 to §3 |
| Dental chart, treatment plan and billing link; recall date | 04 §3 |
| The app in a closed test with Divine Dental, then in production; push through FCM | 08 §1, §3 |
| Thermal receipt layouts | 06 §6 |
| Measure the ten journeys | 06 §1 |

### Phase 2: clinical standards and patient links (weeks 7 to 12)

| Item | File |
|---|---|
| Coded diagnoses, offline picker, backfill worklist | 04 §1 |
| HMIS 105, 108 and 033b with the missing-data panel; notifiable disease alert | 04 §2 |
| Refunds and credit notes; procurement (suppliers, purchase orders, goods received) | 04 §7, §8 |
| Pregnancy record, immunisation register, growth charts | 04 §4 |
| Patient links for results, discharge and prescriptions; verification QR on every PDF | 05 §4 |
| Write audit on every clinical and money model; read audit; sensitive fields encrypted | 07 §3 |
| Data Protection Act pack: DPA, privacy notice, rights, retention, breach runbook, DPIA, PDPO registration | 07 §4 |
| A hospital's full data export | 07 §5 |
| The first six guides, and help links on every screen | 02 §7, 06 §4 |
| New Insight rules for money leaks and follow-up; the owner's daily WhatsApp summary | 09 §1, §2 |

### Phase 3: reach and depth (months 4 to 6)

| Item | File |
|---|---|
| WhatsApp Cloud API with templates and fallback to SMS; message credits and bundles | 05 §1, §3 |
| Clinic public pages and online booking | 05 §5 |
| EFRIS connector with the manual adapter | 04 §8 |
| Insurer claim forms, pre-authorisation, batch submission | 04 §8 |
| Country pages (Uganda, Kenya, Tanzania, Rwanda), the remaining practice pages, guides 7 to 12 | 02 §2, §7 |
| Languages phase 1 (patient words and reception screens), web and app | 06 §5, 08 §7 |
| App: QR provisioning, server list, brand colour, SQLCipher, encrypted drafts, biometrics, crash reports, Wave 3 offline actions | 08 §4 to §8 |
| The partner programme | 02 §9 |

### Phase 4: interoperability and intelligence (month 6 onwards)

| Item | File |
|---|---|
| FHIR read API and patient export bundle; structured referrals | 04 §9 |
| DHIS2 push with confirmation | 04 §2 |
| Lab analyser bridge | 04 §6 |
| Forecasting (no-shows, revenue, occupancy) | 09 §3 |
| AI helpers behind the switch | 09 §4 |
| Benchmarks between consenting clinics | 09 §5 |
| Per-client app builds; tablet two-pane layouts; dark mode | 08 §5, §10 |
| Penetration test before the first large hospital | 07 §6 |

## 2. How we measure success

| Measure | Where it comes from | First target |
|---|---|---|
| Paying hospitals | subscriptions plus standalone licences | owner sets |
| Trial to paid conversion | `TrafficRecorder` funnel | 25% |
| Time from sign-up to first paid visit | onboarding events | under 1 day |
| WhatsApp clicks that become trials | `/go/whatsapp` plus sign-ups | tracked from week 3 |
| Organic search visits per month | Search Console | doubles each quarter in year one |
| Staff active per hospital per week | sign-ins and actions | 80% of seats |
| Messages delivered | channel status | 95% |
| No-show rate after reminders | appointments | down by a third |
| HMIS reports produced from the system | report runs | every hospital that reports |
| Uptime | the monitor | 99.9% |
| Data-loss incidents | incident log | zero |
| Support conversations per hospital per month | WhatsApp tracking | going down |

## 3. Definition of done (every item, every phase)

- **It reuses** the existing Service, component or pattern named in its file, or the report explains why it could not.
- **The server owns the rule;** web, API and app call the same Service.
- **Tenancy:** every new tenant model has the hospital scope and an isolation test.
- **Money and clinical writes:** in a transaction, idempotent where retried, bcmath for money, auditable.
- **Offline:** anything the app writes is safe to retry and honest about what is waiting.
- **Tests** cover the new behaviour; `composer ci` is green; `flutter analyze` and `flutter test` are green for app work.
- **The screen** has been looked at in screenshots at phone and desktop widths.
- **The module's doc** in `docs/` is updated in the same commit; non-obvious decisions are in `docs/decisions.md`.
- **No hard-coded contact,** colour, price or placeholder.
- **Nothing is pushed, deployed or released** without the owner asking (the `AGENTS.md` rule).

## 4. Decisions only the owner can make

Record each answer in `docs/decisions.md` before the work that needs it:

1. **Prices:** plan names, monthly and yearly prices, add-ons and the one-off price list (02 §5).
2. **The app ID** for both stores (08 §1).
3. **The WhatsApp setup:** the Business app now; which Cloud API provider and number later (03 §6, 05 §1).
4. **The SMS account and sender ID** with Africa's Talking (05 §1).
5. **Hosting and backup storage:** where the servers and the off-site backups live (07 §1, §4).
6. **Registration** with the Personal Data Protection Office (07 §4).
7. **Which practice pages come first** after dental (02 §2).
8. **Whether "System by True-Doctor" shows** on patient documents by default on SaaS (03 §1).
9. **The partner commission** and support split (02 §9).
10. **Whether and which AI provider to use,** and the data agreement with it (09 §4).
