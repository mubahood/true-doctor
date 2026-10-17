# 02. Selling and the public site

**Goal:** a clinic owner in Mbarara, Gulu or Nairobi searches "clinic management software" or "dental software Uganda", lands on a True-Doctor page that answers them, sees the real product, chats to us on WhatsApp in one tap, opens the live demo, and starts a trial the same day.

**What exists and stays:**

- `layouts/marketing.blade.php`, `resources/css/marketing.css`, `MarketingController`;
- `VisitorRegion` (currency by region), `LandingIntent` (ad sitelinks) and `TrafficRecorder` with Google Ads offline conversions;
- the demo door;
- `PublicSiteTest`, `MarketingSignupTest` and `LandingTrafficTest`.

The rules in `docs/public-site.md` stay too: no Livewire or Alpine on public pages, everything works without JavaScript, one H1 per page.

**Borrowed from Budget Pro** (`htdocs/budget-pro-new`), where these are already proven:

- `config/site.php`: company facts printed only when set.
- `x-site.icon`: inline SVG instead of an icon font.
- `x-site.shot` with `scripts/site-shots.js`: real screenshots captured by Playwright, with a size manifest.
- `x-site.structured-data`, with the live price range.
- A public demo shared by all visitors and healed on a schedule (`PublicDemoBuilder`).
- `/get-app` with a QR code.
- `SiteTest`, which bans placeholders, and `PerformanceBudgetTest`.

---

## 1. Positioning (one line, used everywhere)

> **True-Doctor: the clinic and hospital system that keeps working when the network doesn't, talks to patients on WhatsApp, and gives you Ministry-ready reports.**

Each audience gets its own proof:

| Audience | What they care about | Our proof |
|---|---|---|
| Private clinic (1 to 10 staff) | Money leaks, debtors, simple, cheap phone | Visit closes only when paid; debtors list; works on Android offline |
| Dental clinic | Tooth chart, treatment plans, recalls, price ranges | Dental chart (04), recall messages (05), price ranges (exists) |
| Maternity and birth centre | ANC visits, EDD, deliveries, immunisation | Pregnancy record, immunisation register (04) |
| Eye, ENT, physio, skin | Their own record, packages | Specialty packs plus specialty forms (04) |
| Laboratory and imaging centre | Worklists, results to patient fast | Lab bench, results link on WhatsApp (05) |
| Hospital (private, faith-based, NGO) | Wards, insurance, books, HMIS, many users | Inpatient, insurance ledger, double-entry books, HMIS 105/108 (04) |
| Anyone who wants their own server | Data stays with them, one payment | Standalone edition, installer, updates, backups |

## 2. Site map: independent pages, each complete on its own

This stays a multi-page site, not a single-page app. Every page is a full server-rendered document with:

- its own title, description, canonical, OG image and JSON-LD;
- the WhatsApp button (03);
- a demo and trial call to action.

Every page can be the first and only page someone reads.

| URL | Page | New or existing | Tag |
|---|---|---|---|
| `/` | Home | existing, rewritten (§4) | P0 |
| `/features` | All modules overview | existing | P1 |
| `/features/{module}` | One page per module: patients, visits, appointments, lab, radiology, pharmacy, inpatient, billing, insurance, books, reports, offline, app | new | P1 |
| `/for/{practice}` | One page per practice type, driven by `App\Support\Practice\Sector`: dental clinics, private clinics, maternity centres, eye clinics, laboratories, imaging, physiotherapy, hospitals, faith-based hospitals | new | P1 (dental P0) |
| `/{country}` | Uganda, Kenya, Tanzania, Rwanda: local currency, mobile money names, regulator, reporting system | new | P1 |
| `/pricing` | Plans, add-ons, one-off copy, FAQ | existing, extended | P0 |
| `/demo` | The live demo door: pick a role, open it (§6) | new (the door exists for the app) | P0 |
| `/walkthrough` | Book a 20-minute walkthrough on WhatsApp or a call | new | P0 |
| `/own-your-copy` | The standalone sale (keep `/standalone` as a 301) | existing, renamed | P1 |
| `/customers` | Case studies and real quotes, with permission (Divine Dental first) | new | P1 |
| `/guides` and `/guides/{slug}` | Useful articles for clinic owners (§7) | new | P1 |
| `/compare/{slug}` | True-Doctor vs paper registers, vs spreadsheets, vs a system that needs internet | new | P2 |
| `/partners` | IT partners and resellers in the districts (§9) | new | P2 |
| `/get-app` | The Android app: store link, QR code, what works offline | new (pattern from Budget Pro) | P1 |
| `/whats-new` | Public changelog, written for clinic owners | new | P2 |
| `/about` | SOLAVIA GROUP LIMITED, the team, where we are, how to reach us | new | P1 |
| `/security`, `/privacy`, `/terms`, `/contact` | existing; privacy gains the Data Protection Act section (07) | existing | P0 |
| `/h/{slug}` | Free public page for each hospital that opts in (05) | new | P2 |

**Single sources of truth, so pages are config, not copy-paste:**

- `config/landing.php`: one entry per practice, module and country, holding the H1, intro, sections, FAQ, screenshots, OG image, related links and schema type. Practice entries are keyed by the `Sector` keys that already exist.
- **One Blade view per page type** (`marketing/for.blade.php`, `marketing/module.blade.php`, `marketing/country.blade.php`, `marketing/guide.blade.php`) composed from shared partials. Each entry has its own words and section order; it is never the same text with the name swapped.

## 3. Technical SEO (P0 unless marked)

- **`robots.txt`:**
  - make it a route or a fixed file with an absolute `Sitemap: https://true-doctor.online/sitemap.xml`;
  - disallow `/admin`, `/super`, `/my-licence`, `/s/`, `/field`, `/test-login` and `/install.php`.
- **Sitemap:**
  - a sitemap index with `sitemap-pages.xml`, `sitemap-guides.xml` and later `sitemap-clinics.xml`;
  - every public page, with `lastmod` taken from the page entry's `updated` date in `config/landing.php`, or the guide's date.
  - Add `/own-your-copy` and every new page.
- **Head tags on every page:**
  - unique `<title>` (at most 60 characters), unique description (at most 155);
  - canonical;
  - `og:image` per page (1200×630, generated by a script into `public/images/og/`);
  - `twitter:title`, `twitter:description`, `twitter:image`;
  - `<html lang>` from the page's locale;
  - `hreflang` when a Swahili version exists (P2).
- **`noindex`** on `/register`, `/admin/*`, `/my-licence*`, `/s/*` and `/test-login`. Give `/register` its own description ("Start your free trial"), not "Staff sign-in".
- **JSON-LD:**
  - `SoftwareApplication` with a real `AggregateOffer` (`lowPrice`, `highPrice`, `priceCurrency`, `offerCount` from the `plans` table, the way `budget-pro-new` does it);
  - `Organization` (SOLAVIA GROUP LIMITED, logo, contact point with the WhatsApp number, `sameAs` the YouTube channel and social links);
  - `WebSite`;
  - `BreadcrumbList` on every inner page;
  - `FAQPage` where there is an FAQ;
  - `Article` on guides;
  - `VideoObject` where a video is embedded.
- **Weight and speed:**
  - the home page at most **200 KB** on first load;
  - LCP under 2.0 s on a slow 4G phone, CLS under 0.05.
  - Replace Font Awesome on public pages with inline SVG icons (port `budget-pro-new/resources/views/components/site/icon.blade.php`).
  - Self-host Inter in two weights (400 and 600), subset, `woff2`, preloaded.
  - Images as WebP with width and height; the LCP image gets `fetchpriority="high"` and the rest are lazy.
- **Real screenshots:**
  - port `budget-pro-new/scripts/site-shots.js`;
  - seed a believable demo hospital in UGX ("Kato Medical Centre, Mukono");
  - capture the dashboard, a patient record, the visit screen, the lab bench, the cashier, the ward board, the dental chart (once built) and the app on a phone;
  - retire the drawn `product-shot`.
- **Internal links:** every module page links to the practices that use it and back; every guide links to one product page; breadcrumbs everywhere.
- **Search engines:** verify the domain in Google Search Console (the file exists) and Bing Webmaster Tools; submit the sitemap index.
- **Tests:** extend `PublicSiteTest` so every public page in `config/landing.php`:
  - renders a unique title and description, canonical, OG image, valid JSON-LD and the WhatsApp link;
  - never shows `noreply@` or a placeholder;
  - has its query count held by a performance test.

## 4. The home page (P0)

At most seven sections, each point made once:

1. **Hero:**
   - H1: "Run your clinic from one record, even with no network."
   - One sentence on WhatsApp and Ministry reports.
   - Buttons: **Start free for 14 days** and **Open the live demo**.
   - A WhatsApp line under them: "Questions? WhatsApp us on +256 783 204 665."
   - A real screenshot on a phone and a laptop.
2. **A day at Kato Medical Centre:** a timeline from 7:30 (reception opens) to 19:00 (the cashier's day closes and balances), each step with a real screenshot. This replaces the six-card strip.
3. **Made for your kind of practice:** links to the `/for/*` pages (dental, maternity, eye, laboratory, hospital).
4. **Works with no network:** one honest section (Field Mode).
5. **Patients hear from you:** WhatsApp receipts, reminders and results (once 05 is built; shown only when true).
6. **Pricing summary** with the per-day price ("about UGX 1,700 a day") and the one-off option.
7. **FAQ:** EFRIS, insurance, printers, a stolen phone, data ownership, moving from paper.

The closing band (green, WhatsApp plus trial) appears on the home page only.

## 5. Pricing (P0 to show, owner decision to change)

**Today:** Starter UGX 10,000, Professional 50,000, Enterprise 100,000 a month (`PlanSeeder`), and a one-off copy that sold to Divine Dental for UGX 3,000,000.

**Proposal** (the owner decides the numbers; the page reads them from the `plans` and `standalone_products` tables):

- **Name plans after practices,** not sizes: Clinic, Medical Centre, Hospital. Limits by staff and beds, never by patients (a clinic must never be stopped from seeing a patient).
- **Show the per-day price** next to the monthly one, and a yearly price with two months free.
- **Add-ons:**
  - WhatsApp and SMS message bundles (a new revenue line, tied to 05);
  - extra branches;
  - the insurance module for small plans;
  - setup and training on site;
  - data migration from paper or spreadsheets.
- **The one-off copy:**
  - a price from the `standalone_products` table;
  - "includes 12 months of updates", renewal price shown, hardware and hosting advice;
  - a link to book an installation.
- **Research before changing prices.** Collect what Ugandan clinic and hospital systems charge, and what clinics pay today for paper registers, receipt books and a part-time records clerk. Record the decision in `docs/decisions.md`.
- **Bring the pricing docs in line.** Fix the doc drift on the exchange rate (`USD_TO_UGX_RATE`, 3800 in config, 3600 in some docs) and on Pesapal vs Flutterwave for subscriptions.

## 6. The funnel, step by step

| Step | What happens | Build | Tag |
|---|---|---|---|
| Visit | Page answers the search; WhatsApp button always visible | §2 to §4, 03 | P0 |
| Talk | One tap opens WhatsApp with a prefilled message naming the page ("Hello True-Doctor, I run a dental clinic and saw your dental page") | 03 | P0 |
| Try | `/demo` shows role cards (owner, doctor, receptionist, cashier, nurse, lab, pharmacist, dentist) and opens a shared public demo hospital. It is healed nightly and rebuilt weekly (pattern: Budget Pro `PublicDemoBuilder`). It locks dangerous actions (passwords, deleting, billing, the team). Turn the demo on in production | reuse the existing demo door and `DemoPersonas` | P0 |
| Start | Trial sign-up asks for the WhatsApp number (required, with country code) and the practice type, and preselects the plan from the page the person came from (`?plan=`, `?practice=`) | `RegistrationController`, `RegistrationService` | P0 |
| Welcome | The owner gets a welcome email and a WhatsApp message (click-to-chat today, API later) with a link to book a free setup call | `WelcomeToTrial`, 05 | P0 |
| Activate | The setup wizard measures time to first patient registered and first visit paid. A checklist shows on the dashboard until both are done | `app/Support/Onboarding/*` | P1 |
| Nudge | Day 1, 3, 7 and 12 messages, each with one useful action and the WhatsApp number | `TrialEnding` plus new notifications | P1 |
| Pay | Pay by mobile money or card through Pesapal from `/admin/subscription`; invoice carries SOLAVIA's details | existing checkout | P0 |
| Grow | Upgrade prompts from real limits (staff, beds), message bundles, branches, the one-off copy | `PlanLimit` | P1 |
| Refer | Each hospital has a referral code; a referred clinic that pays gives both one month free | new `referrals` table, sign-up field | P2 |

**Every step records an event in `TrafficRecorder`:** `whatsapp_click`, `demo_open`, `signup_start`, `signup_done`, `first_patient`, `first_paid_visit`, `paid`. `/super/traffic` shows the funnel by source and landing page.

## 7. Content that earns search traffic (P1)

A `/guides` hub. Each guide:

- is written for a clinic owner and cites its sources;
- is dated and kept current;
- is signed by a real person (Muhindo Mubaraka, MSc Computer Science, building health systems since 2021);
- ends with one link to the product page that solves the problem.

**First twelve:**

1. How to register a private clinic in Uganda (UMDPC steps and documents).
2. HMIS 105 explained: what a clinic reports each month and how to fill it.
3. Running a clinic with no internet: what to keep on paper and what not.
4. How to set clinic prices that cover your costs.
5. Getting paid by insurers in Uganda: claims that come back paid.
6. Stopping money leaks at the cashier: ten checks.
7. Dental clinic records: what the law and good practice expect.
8. ANC visits and the mother's card: keeping the record complete.
9. Stock and expiry in a small pharmacy: a simple routine.
10. EFRIS for health facilities: who must issue e-receipts.
11. Patient data and the Data Protection and Privacy Act 2019: what a clinic must do.
12. Choosing a clinic system: 15 questions to ask any vendor.

**Video:** use the YouTube channel (Learn It With Muhindo, 23,000+ subscribers) for a True-Doctor playlist, one short video per role. Embed with a click-to-load facade (no player weight until clicked) and `VideoObject` schema.

## 8. Trust signals (P0)

- **Company facts,** printed only when set (port `config/site.php`): legal name SOLAVIA GROUP LIMITED, registration number, address, P.O. Box, WhatsApp, phone, email, support hours. They go in the footer, `/about`, `/contact`, the Organization schema, invoices and the terms.
- **A real contact email** (`hello@` or `support@`), never `noreply@`. Set `MAIL_ENQUIRIES_TO`.
- **Customers:** only real ones, named with permission, with a photo and a quote.
- **Security page with true claims only:**
  - where data is hosted;
  - backups and their schedule (07);
  - who can see what (RBAC);
  - the audit trail;
  - the Data Protection Act and, once obtained, the registration with the Personal Data Protection Office.
- **A public status page link** (07).

## 9. Partners and local reach (P2)

- **IT partners in the districts** install the one-off copy, train staff and give first-line support, for a commission. `/partners` explains it; a partner gets a referral code and a kit (slides, demo script, price list).
- **Google Business Profile** for True-Doctor in Kampala, with the same name, address and phone as the site.
- **"Powered by True-Doctor"** with a link on hospital public pages (`/h/{slug}`) and optionally on documents (03).

## 10. Definition of done for this file

- Every page in §2 marked P0 and P1 is live, and every one passes the §3 checks in `PublicSiteTest`.
- The demo works in production from the site and the app.
- Sign-up collects the WhatsApp number.
- The funnel events show in `/super/traffic`.
- Real screenshots replace the drawing.
- The home page is at most 200 KB and passes the speed budget on a throttled phone profile.
