# True-Doctor roadmap: from a strong system to the clinic system Uganda chooses first

**Written:** 17 October 2026, from a full read of this repo, the app (`true-doctor-mobo`) and the docs.
**Owner:** SOLAVIA GROUP LIMITED (Muhindo Mubaraka).
**Status:** plan. Nothing in these files is built unless a file says so.

## Why this plan exists

True-Doctor is already deep. It has:

- Patients, visits, orders, lab, radiology, pharmacy and stock, inpatient, billing, cards, insurance and double-entry books.
- 16 practice packs and two editions (SaaS and standalone).
- An offline sync engine, about 2,500 server tests and a native app covering the whole web menu.

Very few clinic systems in East Africa have this much underneath.

What is holding it back is not the engine. It is five things around it:

1. **People cannot find it.** There is no specialty or city content, a thin sitemap and no real screenshots.
2. **People cannot reach us.** There is no WhatsApp or phone anywhere on the site, in the back office or in the app. The public email shown in production is `noreply@`.
3. **People cannot try it.** The demo is off in production, while the ads promise a live demo.
4. **The clinical record does not speak the country's language.** Diagnoses are free text, so there is no ICD coding, no HMIS 105/108 reports and no EFRIS. The specialty packs, including dental for our first standalone client, are price lists and not clinical tools.
5. **Patients feel nothing.** Live SMS is not wired, there is no WhatsApp, and there are no results, receipts, recalls or online booking.

This plan fixes those, keeps everything that already works, and holds the product to two standards at once:

- **World standard:** coded data, interoperability, security, accessibility and speed.
- **Local standard:** WhatsApp first, mobile money, works on a cheap phone with no network, MoH forms, Luganda and Swahili, and prices a Ugandan clinic can say yes to.

## The files

| # | File | What it covers |
|---|---|---|
| 01 | [System review](01-system-review.md) | Where we are, honestly: strengths, gaps and risks, with file paths |
| 02 | [Selling and the public site](02-selling-and-public-site.md) | SEO, landing pages, the funnel, pricing, demo, trust, analytics |
| 03 | [WhatsApp and support everywhere](03-whatsapp-and-support-everywhere.md) | One support contact, shown on every surface: web, back office, app, emails, PDFs, errors |
| 04 | [Clinical depth and standards](04-clinical-depth-and-standards.md) | ICD, HMIS/DHIS2, dental chart, maternity, immunisation, lab codes, FHIR, insurance, EFRIS |
| 05 | [Patients and engagement](05-patients-and-engagement.md) | WhatsApp and SMS messages, consent, results links, online booking, clinic pages, document verification |
| 06 | [Simple to use](06-simple-to-use.md) | Fewer clicks, better defaults, help in place, languages |
| 07 | [Robust, secure, compliant](07-robust-secure-compliant.md) | Operations, backups, monitoring, 2FA, audit, encryption, Uganda's Data Protection Act |
| 08 | [The mobile app](08-mobile-app.md) | Push, deep links, white-label, server provisioning, encryption, languages, release |
| 09 | [Intelligence](09-intelligence.md) | Insights, AI help for notes and coding, forecasting, done safely |
| 10 | [Delivery plan](10-delivery-plan.md) | Order of work, phases, measures of success, definition of done |

The agent command that executes this plan is [`../agent-command.md`](../agent-command.md).

## Rules every item in this plan keeps

These come from `AGENTS.md` and `docs/agents/`, and they are not up for negotiation:

- **One codebase, two editions.** No forks and no "if client X".
- **The server is the authority.** Web, API and app call the same Service.
- **Tenancy is safety-critical.** Global scopes, plus an isolation test on every new tenant model.
- **Offline is a promise.** Idempotent writes, honest pending state, nothing typed is lost.
- **Powerful behind, simple in front.**
- **Money and clinical writes are pessimistic and auditable.**
- **Prove it with tests,** `composer ci` and screenshots.
- **Reuse before building.** Every item names the existing file it extends.

## Priority tags used in every file

- **P0:** a blocker for selling or trust, or a risk to data or money. Do first.
- **P1:** a big step towards the standard. Do next.
- **P2:** worth doing once P0 and P1 are done.

## The one number on every surface

The support WhatsApp is **+256 783 204 665** (digits for links: `256783204665`). It is stored once and read everywhere; see [03](03-whatsapp-and-support-everywhere.md). It is never typed into a view.
