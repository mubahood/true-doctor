# Phase 1 report: the selling engine and the dental client

**Dates:** 17 October 2026. **Repos:** server (`true-doctor`), app (`true-doctor-mobo`). **Plan of record:** `docs/roadmap/10-delivery-plan.md` §1, Phase 1; the order of work is `phase-1-plan.md`.

## What shipped

| Item | What it is now | Where |
|---|---|---|
| Home page (02 §4) | Seven sections built only from what is true today: one record and no network; a day at Kato Medical Centre from 07:30 to 19:00 with a real screenshot at each step; the practice pages; what works with no signal and what waits; prices read from the tables with the price of a day; questions clinic owners ask first; the green closing band. The ad keyword "Hospital management system" leads the H1 so the campaign's landing test still holds. "Patients hear from you" appears by itself once a live SMS gateway is configured. | `marketing/home.blade.php`, `EditionHomeController` |
| Weight and speed (02 §3) | No icon font and no Google font on any public page (inline SVG, Inter served from this server, preloaded); screenshots in WebP with an 800-wide copy for phones, sized so nothing jumps, lazy below the fold. First load about 170 KB on a desktop and 130 KB on a phone, held under 200 KB by a test. No em dash on any public page, also by a test. | `x-site.icon`, `x-site.shot`, `HomePageTest` |
| Real screenshots (02 §3) | `scripts/site-shots.mjs` signs in as each role of the demo hospital and captures the dashboard, the visits list, a visit, the lab, the dental chart, the ward, the cashier, the cashier's day and a phone view; `scripts/site-og.mjs` makes each public page's share picture from its own heading and screenshot. The drawn product mock is gone. | `public/images/site/shots/`, `public/images/og/` |
| Technical SEO (02 §3) | robots route, sitemap index with lastmod, noindex on sign-in pages, per-page OG and Twitter tags, JSON-LD: SoftwareApplication with the live price range, Organization, WebSite, BreadcrumbList, FAQPage. | layout, `MarketingController` |
| Practice pages (02 §2) | Dental clinics, private clinics, maternity centres and hospitals, each with its own words, a real screenshot, an "on the way" list and its questions; plus `/walkthrough`, `/get-app`, `/about`, `/demo`, `/own-your-copy`. | `config/landing.php`, `PublicPageController` |
| Pricing (02 §5) | One price per clinic and never per patient; the price of a day beside each plan; the one-off copy and the help sold by the job; "Not sure? WhatsApp us". Prices are the owner's, read from the tables. | `marketing/pricing.blade.php` |
| Plans never cap patients | Starter stopped a clinic at its hundredth patient; plans now limit staff and beds only, on the online service as on a clinic's own server. Existing plans were cleaned by a migration. | `PlanLimit`, migration `2026_10_20_110000` |
| The funnel (02 §6) | Every WhatsApp button on the public site goes through `/go/whatsapp`, which counts the tap and sends on to wa.me; demo entries, first patient registered and first visit paid are milestones. `/super/traffic` shows arrived, second page, pricing, demo, WhatsApp, sign-up form, trial, first patient, first payment, paid. | `GoController`, `Funnel`, `Super\Traffic` |
| Messages to patients (05 §1 to §3) | Consent per channel and purpose recorded at the desk (three switches), in the app and offline; the booking, the day-before reminder, the receipt and the dental recall go out through one service that checks consent, holds quiet hours, sends once per event, takes credit before sending and gives it back if it fails, and logs every message with its status. Africa's Talking by configuration; delivery reports and STOP through a secret-path webhook. Settings → Messages for the clinic, Message credit for the super admin. | `app/Services/Messaging/`, `docs/messaging.md` |
| Dental (04 §3) | The FDI chart, the plan, the quote, billing on the open visit and the recall, on the web, through the API, and now in the app: a Dental tab that charts offline through the outbox, a child under twelve on milk teeth, the chart opening only where there is dentistry. | `DentalService`, app `dental_tab.dart` |
| Push through FCM (08 §3) | The FCM HTTP v1 driver behind the existing push seam, signing its own JWT; a dead token is cleared. | `FcmPushTransport` |
| Receipt printers (06 §6) | Receipts and queue tickets on 58 or 80 mm roll paper, chosen once in Billing settings. | `ThermalController`, `pdf/thermal/` |
| The cashier's day on the web | The app's day summary had no web screen; Billing → Cashier's day shows the same figures and prints the same PDF. | `Billing\CashierDay` |
| Ten journeys measured (06 §1) | Scripted on the web against the demo hospital; the run found four problems, three fixed (phone search in +256 form, results on an order still Ordered, a row menu that would not reopen) and one recorded (taking cash is 14 clicks). | `docs/roadmap/journeys.md`, `scripts/journeys.mjs` |

## Bugs found and fixed on the way

- **Appointment reminders at the wrong hour on the online service.** Bookings are the clinic's wall-clock time; the reminder command read them as UTC, so a 09:00 Kampala appointment's "two hours before" notice came at 10:00, after the patient was seen. Fixed with `WallTime`, with a test that runs the clock in UTC as production does.
- **The demo told lies a doctor would see.** Pregnancy diagnoses on an eight-year-old boy and an 81-year-old woman, an antenatal booking for a young man, a 14-year-old "holder" of a family card with a 15-year-old "spouse", root canals on a six-year-old. Each clinical story and appointment reason now fits the patient's age and sex, and a test checks every person on the roster.
- **Table headings never right-aligned** (the utility lost to the table rule), so every money column's heading sat apart from its figures; fixed once in the stylesheet.
- **The super-admin top-up form** would have collided with Livewire's own `$messages`.
- **STOP replies** would not have matched numbers typed with spaces; **recalls** due today would have been skipped by a date-string comparison. Both found by their tests before shipping.
- **App:** a notification re-plan made during another returned the old plan instead of waiting; two tests that failed only on a busy machine were made exact.

## Tests added

Server: `HomePageTest` (content, real screenshots, no icon font, no em dash, 200 KB budget), `PatientMessagingTest` (21), `GoWhatsappTest`, `ThermalPrintTest`, `FcmPushTest`, `CashierDayTest`, dental tests for the tab rule, the child chart and the API quote, the funnel test in `TrafficDashboardTest`, the demo-story test in `DemoDataTest`, the UTC reminder test in `NotifyEventsTest`, `PlanLimitTest` rewritten for "never patients". App: `dental_tab_test` (chart, offline outbox, nurse view, done and bill with its key, milk teeth, the schema 2 upgrade), the registration consent test.

## What could not be done, and why

- **The app's push receiver** needs the Firebase project's configuration files in the app build; only the owner can create the project. The server side is ready.
- **The app's closed test with Divine Dental, then production** needs the Play Console. The app ID, listing and Data safety answers are ready.
- **Live SMS** needs an Africa's Talking account, an approved sender ID and the callback URLs set in it (`docs/messaging.md`, "Going live"). Until then the server stays on the log driver and no clinic can switch messages on.
- **WhatsApp messages to patients** are Phase 3 (the Cloud API and its templates); the consent already records WhatsApp.
- **X-rays and photos on a tooth, and the periodontal chart,** are listed as "on the way" on the dental page.

## Decisions the owner needs to make

1. **Prices.** Shown as they are (10,000, 50,000, 100,000 UGX a month). A yearly price with two months free is not offered until you decide it.
2. **Message bundles.** The price of a bundle of texts (Africa's Talking charges about UGX 30 a message; a bundle of 500 at UGX 25,000 leaves room for support and failures).
3. **The Firebase project** for push, and **the Africa's Talking account** for SMS.
4. **Permission to name Divine Dental** on a customers page.
