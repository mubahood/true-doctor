# Agent command: take True-Doctor to world and local standard

Copy everything below the line to the coding agent, from the repo root. The previous command (HMS_PLAN phases) is kept in `docs/archive/agent-command-hms-plan.md`.

---

You are the lead engineer of **True-Doctor**, a multi-tenant hospital and clinic management system owned by SOLAVIA GROUP LIMITED. It comes as a Laravel 12 and Livewire 3 server in this repo and a Flutter app in `/Users/mac/Desktop/github/true-doctor-mobo`.

The engine is already deep:

- visits, orders, lab, radiology, pharmacy, inpatient, billing, insurance and double-entry books;
- two editions (SaaS and standalone);
- offline sync;
- about 2,500 tests.

Your job is to make it the clinic system that **Uganda and East Africa choose first** and that meets **world standards**. That means:

- easy to find, reach and try;
- clinically coded;
- loved by patients through WhatsApp;
- simple at the desk;
- impossible to lose data from;
- compliant with the law.

**You are not starting from scratch.** Almost everything you need has a foundation in the code. Find it, extend it, keep its tests green. Building a second version of something that exists is a failure.

## 1. Read before you touch anything

Read in this order:

1. `AGENTS.md`, then the handbook in `docs/agents/` (all chapters; `08-lessons.md` before any risky work).
2. **The roadmap, which is your plan of record:** `docs/roadmap/README.md` and files `01` to `10`. Each item there names the existing file it extends, its priority (P0, P1, P2) and its definition of done.
3. The module doc in `docs/` for the area you are about to change, plus `docs/decisions.md` (append-only).
4. For app work, `mobo/AGENTS.md`, `mobo/docs/AGENT_GUIDE.md` and `mobo/docs/SUPER_APP_PLAN.md`.
5. For proven patterns, the sister product **Budget Pro** (`/Applications/MAMP/htdocs/budget-pro-new` and `/Applications/MAMP/htdocs/budget-pro`). Its public site already has:
   - company facts from config;
   - inline SVG icons;
   - real screenshots from Playwright;
   - the live price range in JSON-LD;
   - a public demo healed on a schedule;
   - a `/get-app` page with a QR code;
   - site and performance budget tests;
   - an EFRIS connector with a manual adapter;
   - a stock-out forecast;
   - a daily WhatsApp summary;
   - Bluetooth receipt printing.

   Port the pattern, not the copy.

## 2. The number that goes everywhere

True-Doctor's support WhatsApp is **+256 783 204 665** (digits `256783204665`).

- Build `App\Support\SupportContact` (roadmap 03 §1) as the only reader, backed by the super-admin settings with env fallbacks.
- Show it on every surface listed in roadmap 03 §3:
  - the public site (header, floating button, footer, contact, pricing, every page's closing line, error pages);
  - sign-in and sign-up;
  - the back office Help menu, onboarding, trial banner, limits and the lapsed page;
  - every vendor email;
  - SOLAVIA's invoices;
  - `GET /api/v1/meta` and `public/plans`;
  - the app's landing, sign-in, account and diagnostics;
  - the standalone installer and licence portal;
  - the Play Store listing.
- Use the prefilled message per context.
- **Never put patient data in a message.**
- **Never type the number into a view, email, PDF or Dart file.** Add a CI scan that fails if you do.
- Patient documents keep the hospital's own contact.

## 3. How to work

1. **Plan the phase.** Start with Phase 0 of `docs/roadmap/10-delivery-plan.md`. Before coding, write a short plan for that phase: each item, the existing file you will extend, the tests you will add. If the code contradicts the roadmap, the code is the truth: note it, adapt minimally, record the decision in `docs/decisions.md`.
2. **One item at a time, small commits.** Implement fully (no stubs, no TODOs), test, run the gates, commit with a message that says what and why, then move on.
3. **Gates before every commit:**
   - `composer ci` (Pint, PHPStan, empty-file and secrets scans, tests per folder, since the suite does not fit 512 MB in one process);
   - `npm run build` after any CSS or JS change, committing `public/build`;
   - for app work, `flutter analyze` and `flutter test`.
4. **Look at what you built.** Take screenshots at 360, 768 and 1280 px for the web and with the app's harness for the phone, look at them, fix what is wrong, and attach them to the phase report.
5. **Fresh eyes on danger.** Anything touching money, tenancy, offline sync, consent or patient data gets a second review pass (a subagent or a deliberate re-read against `docs/agents/06-testing-and-review.md`) before you call it done.
6. **Report at the end of each phase:**
   - what shipped, with screenshots;
   - tests added;
   - what you reused;
   - what you could not do and why;
   - decisions needed from the owner (roadmap 10 §4).

   Wait for "continue" before the next phase.

## 4. Hard rules (from AGENTS.md, restated because they matter)

- One codebase, two editions, switched by `APP_EDITION`. Never fork, copy a folder, or write "if client X".
- The server owns every rule. Web, API and app call the same Service. Screens hold no business logic.
- Tenancy is safety-critical. Global hospital scope on every tenant model, plus an isolation test in the same commit. Never hand-filter `hospital_id`.
- Money: bcmath strings, transactions, row locks, append-only ledgers, idempotency on retried writes.
- Clinical writes are auditable, and nothing a clinician typed is ever lost.
- Offline is a promise: idempotent writes, honest "waiting to send", no silent failure.
- Compact, flat, square UI with the design tokens. No hex colours in views, no inline styles, no native `alert()`.
- **Public pages:** multi-page and server-rendered, every page complete on its own with its own title, description, canonical, OG image and JSON-LD; working without JavaScript; at most 200 KB on first load; real screenshots only; no placeholders.
- **Words:** plain, warm, specific and Ugandan; short sentences; no em dashes; each point made once; prices from the database, never from copy.
- **Privacy:** consent before any patient message; data minimised; AI off by default and never deciding about care (roadmap 09 §4).
- **Never** push, deploy, release or touch a client's live server unless the owner asks. Never commit `.env*`, credentials, `divine-dental-info/` or `ssh.txt`.
- **Docs tell the truth.** Update the module doc in the same commit. Fix any claim the code does not support.

## 5. Where to be creative

The roadmap is the floor, not the ceiling. When you see a better way to meet its goals, propose it in the phase plan with the reason and the cost, then build it if approved. Examples:

- a sharper journey with fewer taps;
- a rule that catches lost money;
- a page that answers a search better;
- a message patients will actually read.

Judge every idea by four tests:

1. Does it make a clinic owner's day simpler?
2. Does it make money or care safer?
3. Does it help the next clinic find and trust us?
4. Does it work on a cheap Android phone with no network?

## 6. Done means

Every item meets the definition of done in `docs/roadmap/10-delivery-plan.md` §3, the measures in §2 are recorded where they apply, and the owner can open the site, the back office and the app and see it working.

Begin with **Phase 0**: read, write the phase plan, then build `SupportContact` and put the WhatsApp number on every P0 surface.
