# 07 · Playbooks

Recipes for the common jobs. Each ends with its own done-check.

## A. New back-office screen (web)
1. Pick the shape: List / Editor / Detail / Board ([`../PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md`](../PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md) §4.4). A record you glance at → list + quick view; a workspace → page with tabs.
2. Rules in a Service; validation in a FormRequest `rulesFor()`; ability in a Policy; permission in RBAC seed.
3. Component `app/Livewire/<Module>/…` (thin), view `resources/views/livewire/<module>/…`, `#[Layout('layouts.admin')]`, route in `routes/web.php`; menu entry in `app/Support/Navigation.php` with the right edition and permission ([`../navigation.md`](../navigation.md)).
4. Markup from the kit only; compact densities ([03](03-ui-and-density.md)); serial column; quick view; empty state.
5. Tests per [06](06-testing-and-review.md). `npm run build` if CSS/JS changed. Update the module doc.

## B. New API endpoint + its phone screen (the full vertical)
1. Service method (reuse if the web already has it). Controller, Resource, route, Policy. Money as strings, uuids, omit-what-you-can't-see.
2. If it is a *summary* many surfaces want, make one service and have web and API both read it (`PatientSummaryService` is the model).
3. `docs/api/<group>.md` with a real JSON sample.
4. API tests (401/403/404/422, isolation, replay).
5. Phone: add the screen under `lib/features/<area>/`, register it, keyed by the **web route name** it stands in for, in that area's `*_screens.dart` map (merged by `lib/features/shell/destinations.dart`; the menu itself comes from the server's navigation, and a route with no native screen opens the web page, so nothing is ever hidden); list via `TdListShell`/`TdPagedQuery`; cache read through `ReadCache.through`; writes through the outbox/idempotent client; offline behaviour chosen per [04](04-offline-and-app.md); icons via `tool/app_icon_names.txt`.
6. Call the real local server and compare real JSON with the parser. Run `flutter analyze` + `flutter test`; look at a screenshot.

## C. New printable (PDF)
View under `resources/views/pdf/…` using `<x-pdf.document>`; controller streams inline; route under web and (if the app needs it) API; add to `GeneratedDocumentTest`; check size < 400 KB and the split-layout test; check the letterhead doesn't overlap the first row. Brand from `DocumentBrand`.

## D. New suggestion ("Worth a look")
Rule class in `app/Support/Insights/Rules`, registered in `InsightEngine`: cheap hospital-scoped query, role-aware, one plain sentence, one action route, hidden when not applicable. Test it. The phone renders the list as returned; no app change is needed unless it introduces a new route.

## E. Changing money, stock, cards or insurance
Service only; DB transaction; `lockForUpdate` on the row; bcmath strings; append-only ledger with `balance_after`; honour closed periods; idempotent endpoint; tests for concurrency-sensitive paths and for the replay; **independent review** before done.

## F. Change the look globally
Edit tokens/sources (`resources/css/admin.css` `:root`, `lib/theme/td_tokens.dart`, `td_theme.dart`), rebuild, run `DesignSystemCssTest` and `flutter test`, and look at both. Update [03](03-ui-and-density.md) if a number in its table changed.

## G. Release (only when asked)
Commit; owner decides to push/deploy; deploy `main` to live; stage the version; wait for the proof install; verify the commit in `PACKAGE.json`; publish. App: build with the upload key; tell the owner what was and wasn't tested on a real device.

## H. Emergency on a client's server
See [05](05-editions-and-releases.md) "Hotfixing". Reproduce, smallest change in existing files, backup + rollback ready, verify, mirror in repo, report plainly.

## I. Add documentation
Module docs live in `docs/<module>.md` (short, accurate, with the *why*). Index lines go in `docs/README.md`. Decisions in `docs/decisions.md`. API in `docs/api/`. This handbook only changes when the *approach* changes. Client documents follow the owner's rule: plain PDFs, short emails.
