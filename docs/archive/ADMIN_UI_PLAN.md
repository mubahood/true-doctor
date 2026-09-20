# True-Doctor — Back-Office (Staff) UI/UX Rebuild & Completeness Plan

> Goal: make the entire staff back-office **visually and behaviourally consistent** with the new
> public frontend (light, flat, **square corners**, thin/sharp Inter type, medical-blue accent),
> deliver a **rich, useful dashboard**, and ensure **every feature is fully implemented, wired, and
> permission-aware**. Work top-to-bottom; keep the app booting after every phase.

Status legend: `[ ]` todo · `[~]` in progress · `[x]` done

## ✅ Implementation status (delivered)

- **P1 Design system + shell — DONE.** `public/css/td-admin.css` re-skins the whole back-office
  (light · flat · **square** · thin Inter · medical-blue) by redefining `.ad-*`/`.btn-ad`/`.badge-ad`.
  `layouts/admin.blade.php` rewritten as a clean **Alpine** shell: permission-aware sidebar (live
  pending/credential/fraud badges), topbar with registration search + user menu, self-profile &
  change-password modals (wired to the existing JSON endpoints), square toast flashes. Dropped the
  dark jQuery/ONYX/select2/flatpickr/dropzone/sweetalert2 stack (Alpine + Chart.js + FA only).
- **P2 Dashboard — DONE.** KPI stat cards (click-through to filtered lists), 30-day verifications
  Chart.js line, role-aware work queues (awaiting verification, credentials to review, expiring soon,
  new fraud), by-category breakdown, recent-activity timeline, quick actions.
- **P3–P5, P7 Views — DONE.** Registrations (index/form/workbench), credentials, fraud, categories,
  regulatory-bodies, settings, staff/users, self-profile all squared & token-consistent; removed the
  legacy Trading Wallet card and aligned the user role select to the 6 True-Doctor roles.
- **P6 Analytics + export — DONE.** `export-audit`-gated streaming CSV (registrations + verification
  logs) with buttons on the Analytics page.
- **P8 Consistency — DONE.** Zero `border-radius`/`--gold`/bold-700 left in admin; nav is
  permission-aware (clerk 2 items, registrar 3, verifier/auditor 4, super-admin all).
- **P9 QA — DONE.** Fixed a real security gap (`/admin/users` had no `manage-users` gate). New
  `AdminAccessTest` (guest redirect + per-role gating + settings save). Suite: **19 passed**, Pint clean.

Remaining/optional: institution self-service portal (phase 2), status-change notifications (deferred).

---

## 0. Current state (audit findings)

- Admin shell = `resources/views/layouts/admin.blade.php` + **dark-only** `public/css/admin.css`
  (ONYX design system: `.ad-card`, `.ad-table`, `.btn-ad`, `.badge-ad`). Ignores the theme toggle.
- Loads heavy vendor CSS/JS (`tokens.css`, select2, flatpickr, dropzone, jQuery, sweetalert2,
  chart.js, alpine, `admin.js`). Inconsistent, rounded, dark.
- 24 admin views already exist (registrations, credentials, fraud, categories, regulatory-bodies,
  settings, analytics, users, dashboard) built against the OLD classes — they render but look dark
  and don't match the public light theme.
- Login (`layouts/auth.blade.php`) is **already** on the new light/flat/square system — use it as the
  reference for tokens.
- Controllers & services are complete on the backend (CRUD, verify/suspend/revoke/renew, credential
  review, QR, certificate, import, analytics). The gap is **UI consistency + polish + a few wiring
  details**, not missing business logic.

**Design decision:** keep a **sidebar shell** for the admin (right for a data app with many sections)
but re-skin it with the public design language. Share one token set across public + admin.

---

## 1. Design system & shared tokens  `[ ]`

- [ ] Create `public/css/td-admin.css` — a **new light, flat, square** admin stylesheet (replaces
      `admin.css` for True-Doctor). Base it on the public tokens so both surfaces match exactly.
  - Tokens: `--bg:#f5f7fa`, `--surface:#fff`, `--line:#e2e8f0`, `--line-2:#cbd5e1`, `--tx:#111c2e`,
    `--tx2:#5a6b80`, `--tx3:#93a1b3`, `--pri:#0a6ebd`, `--pri-d:#08588f`, `--pri-soft:#e8f1fb`,
    status `--ok/--warn/--bad` (+ soft), `--sidebar:#0f1b2d` OR light sidebar `#ffffff` w/ border.
  - Type: **Inter** 200–600; base 13px; headings weight 200–300; labels 500 uppercase; **no 700**.
  - **Square corners everywhere** (border-radius 0). Thin 1px hairline borders. Subtle shadows only.
- [ ] Decide sidebar tone: **light sidebar** (white, hairline border, blue active state) to match the
      public light theme — recommended for full consistency. Active item = blue left-bar + blue text.
- [ ] Faded medical **doodle background** (reuse `partials/doodle-bg`) on the content area only, very
      faint, behind cards (optional, tasteful — same texture as public).
- [ ] Reusable component classes: `.tdx-card`, `.tdx-table`, `.tdx-btn`(+`primary/ghost/danger/sm`),
      `.tdx-badge`(status-tinted), `.tdx-input/.tdx-select/.tdx-textarea/.tdx-label`, `.tdx-field`,
      `.tdx-page-head` (title + breadcrumb + actions), `.tdx-filters`, `.tdx-empty`, `.tdx-tabs`,
      `.tdx-stat`, `.tdx-toast`, `.tdx-modal`, `.tdx-drawer`.
- [ ] Trim the asset stack: keep **Alpine** (interactivity) + **Chart.js** (analytics) + Font Awesome;
      drop jQuery/select2/flatpickr/dropzone/sweetalert2 unless a view truly needs them (replace
      select2 with native selects, flatpickr with `<input type=date>`, dropzone with native file input,
      sweetalert2 confirms with a small Alpine confirm modal or native `confirm()`).

## 2. Admin shell / layout  `[ ]`  (`layouts/admin.blade.php` rewrite)

- [ ] Rewrite the layout head: drop "Trading Simulator" comment, load `td-admin.css` + Inter + FA +
      Alpine + Chart.js (deferred). Keep `<title>@yield('title') · True-Doctor`.
- [ ] **Sidebar** (light, square): brand mark (blue square + "True-Doctor · Registry"), grouped nav:
  - **Registry:** Dashboard, Registrations (badge = pending count), Credentials (badge = pending),
    Fraud reports (badge = new).
  - **Insights:** Analytics.
  - **Configuration:** Categories, Regulatory bodies, Settings.
  - **System:** Staff, Queue (Horizon) — only for Super Admin.
  - Nav items **permission-aware** via `@can`/`Route::has` so users only see what they may use.
  - Collapsible on mobile (Alpine toggle), off-canvas drawer.
- [ ] **Topbar:** page title, a global **search registrations** box (jumps to registrations index with
      `?q=`), light/dark toggle (optional), user menu (avatar/initials → My profile, Change password,
      Sign out). Reuse the existing self-profile JSON endpoints (`admin.api.profile.*`).
- [ ] **Flash/toast** system (success/error/warning) — square, top-right, auto-dismiss, Alpine.
- [ ] **Breadcrumbs** partial (`admin.partials.breadcrumb`).
- [ ] Restyle the **status badge** partial (`admin/partials/status-badge`) to the square/thin token set.
- [ ] Ensure the layout **defaults to light** and the theme toggle actually reskins (or drop the toggle
      and commit to light for consistency with the public site — recommended).

## 3. Login & auth polish  `[ ]`

- [ ] Confirm `layouts/auth.blade.php` tokens == admin tokens (already light/square) — align any drift.
- [ ] Post-login redirect lands on the Dashboard (done). Add a friendly "signed in as {role}" toast.
- [ ] Forgot/reset/confirm/verify-email screens: verify they render on the light auth shell (they do)
      and read as True-Doctor (no trading copy). `[x already fixed login copy]`

## 4. Dashboard (rich & useful)  `[ ]`  (`admin/dashboard.blade.php` + `DashboardController`)

- [ ] **KPI stat row** (square stat cards): Total records, Verified, Pending, Suspended, Revoked,
      Expired, Scans today, Expiring in 30 days. Each links to a filtered registrations list.
- [ ] **Verifications-per-day** line chart (Chart.js, last 30d) — data already computed.
- [ ] **Status split** doughnut/bar + **By category** mini-bars.
- [ ] **Work queues** (role-aware):
  - Pending verification (list w/ quick "Open") — verifiers/registrars.
  - Credentials awaiting review — verifiers.
  - New fraud reports — verifiers.
  - Expiring soon (next 30d) with a "Renew" shortcut.
- [ ] **Recent activity** feed from Spatie `activity_log` (who changed what, when) — auditors/all.
- [ ] **Quick actions**: New registration, Review credentials, Bulk import (permission-gated buttons).
- [ ] Controller: extend `DashboardController@index` to also return expiring-soon, pending-credentials,
      recent-activity, and role-aware queue data (guard with `Schema::hasTable`).

## 5. Registrations (core workflow)  `[ ]`

- [ ] **Index**: square table, sticky filter bar (search `q`, status tabs w/ counts, category, regulator,
      district), sortable columns, pagination, empty state, "New registration" (permission-gated),
      per-row actions (view/edit), status badge, expiry with "expiring soon" amber hint.
- [ ] **Create/Edit** (`_form`): grouped fieldsets (Identity, Classification, Licence & validity,
      Location, Notes), native selects/date inputs, inline validation errors, category → auto-suggest
      default regulator & validity (Alpine, optional), cancel/save. Photo upload (Intervention) field.
- [ ] **Show = the workbench** (most important):
  - Left: identity card (photo/avatar, name, category, **status badge**, big code, licence, regulator,
    district, issued/expires, verification count, public notes) + **QR** (image + download) + link to
    the public profile/verify page + "Download certificate".
  - Right: **status state-machine action panel** — Verify / Suspend (reason) / Revoke (reason) /
    Renew (new expiry + note) / Submit — each gated by `@can('verify',$r)`; confirmation modals.
  - **Credentials** panel: list + view (private stream) + Approve/Reject (reason) + upload new.
  - **Status history** timeline (immutable) + **Renewals** table.
  - Edit / Delete (archive, soft-delete) with confirm.
- [ ] Verify these already-wired routes render & post correctly after re-skin.

## 6. Credentials review  `[ ]`  (`admin/credentials/index`)

- [ ] Status tabs (pending/approved/rejected + counts), table (registration + code, type, doc "View",
      status, reviewer, uploaded), Approve / Reject-with-notes actions, secure document stream link,
      empty state, pagination. Consistent square styling.

## 7. Fraud queue  `[ ]`  (`admin/fraud/index` + `show`)

- [ ] Index: status tabs (new/reviewing/actioned/dismissed + counts), table (entity, reason humanised,
      reporter, received, status), row → show.
- [ ] Show: full report, linked registration (with quick actions: open / suspend / revoke), evidence
      view (private stream), status update form. Square styling.

## 8. Configuration — Categories  `[ ]`

- [ ] Index: table (icon, name, group, prefix, validity, #records, active), New, Edit, Delete (guard:
      block if records exist). Toggle active inline (optional).
- [ ] Create/Edit (`_form`): name, group, code_prefix, validity, default regulator, parent, icon
      (with a small icon preview), requires_photo, is_active, sort_order.

## 9. Configuration — Regulatory bodies  `[ ]`

- [ ] Index: table (name, short, website, #records, active) + New/Edit/Delete (guard if backing records).
- [ ] Create/Edit (`_form`): name, short_name, description, website, contact, is_active, logo upload.

## 10. Settings  `[ ]`  (`admin/settings/index`)

- [ ] Grouped form: **General** (site name, tagline), **Contact** (email, phone), **Appearance**
      (default theme), **Security** (verify rate limit), **Languages** (enabled toggles). Save →
      `Settings::set` (done). Show current values, success toast, permission-gated (`manage-settings`).

## 11. Analytics  `[ ]`  (`admin/analytics/index`)

- [ ] Stat row (totals), verifications/day line chart, status doughnut, top-scanned table, expiring-soon
      table, by-category & by-district bars. **Export** buttons (CSV) for verification logs & registry
      (auditor `export-audit`) — add `AnalyticsController@export` + routes if missing.

## 12. Staff / Users  `[ ]`  (`admin/users/*`)

- [ ] Index: table (name/avatar, email, **role badge**, active, last active, joined), New, Edit, Show,
      Deactivate/Activate toggle. Super-Admin only (`manage-users`).
- [ ] Create/Edit: name, username, email, **role select** (the 6 True-Doctor roles), is_admin, is_active,
      phone; sends welcome-credentials email (reuse existing mail). Role change re-syncs Spatie role.
- [ ] Show: profile summary + recent activity by this user.
- [ ] Remove any legacy trading fields/labels from the user forms.

## 13. Self-profile (all staff)  `[ ]`

- [ ] Re-skin the self-profile drawer/modal (name, avatar, phone, bio, change password) using the
      existing `Admin/ApiController` JSON endpoints. Square, light, Alpine-driven.

## 14. Cross-cutting UX  `[ ]`

- [ ] **Permission-aware UI** everywhere (buttons/nav hidden when `cannot`). Verify all 6 roles see a
      coherent, non-broken back office (super_admin, registrar, verifier, auditor, institution_admin,
      data_clerk).
- [ ] Consistent **empty states**, **loading** affordances, **confirm modals**, **toasts**.
- [ ] **Responsive**: sidebar off-canvas on mobile; tables scroll/stack; forms single-column.
- [ ] **Accessibility**: labels, focus states, keyboard nav, aria on modals/menus, WCAG-AA contrast.
- [ ] Pagination component restyled (square).

## 15. Feature-completeness sweep  `[ ]`

- [ ] Registration lifecycle: create → submit → verify → suspend → revoke → renew → expire all reachable
      from the UI and logged (status_histories + activity_log).
- [ ] Credentials: upload → review (approve/reject) → gate verification; private streaming works.
- [ ] QR: generated, shown, downloadable; resolves publicly.
- [ ] Certificate PDF: downloadable from admin + public; correct branding.
- [ ] Bulk import: template download, dry-run report, commit; errors surfaced.
- [ ] Fraud: public submit → admin queue → status transitions.
- [ ] Analytics export (CSV) for auditors.
- [ ] Institution portal (phase 2) — leave scaffolded, note in UI as "coming soon" or hide.
- [ ] Notifications (optional): on status change / expiry — via `VerificationChannel` + mail; note if
      deferred.

## 16. QA, tests, docs  `[ ]`

- [ ] Boot check after each phase (`route:list`, render key pages per role via curl).
- [ ] Add feature tests: dashboard loads per role; registrations index filters; verify/suspend/revoke
      via UI routes; credential approve/reject; settings save; users CRUD gated.
- [ ] `vendor/bin/pint` clean. Update `README` (admin walkthrough) + `ARCHITECTURE` if the shell changes.
- [ ] Final green boot; screenshot walkthrough of each page in light/square theme.

---

## Suggested execution order (phased, each ends green)

1. **P1 — Design system + shell** (§1, §2): new `td-admin.css`, rewrite admin layout, status badge,
   toasts, breadcrumbs. Re-point all views to the new classes (compat shim if needed).
2. **P2 — Dashboard** (§4): controller data + rich view.
3. **P3 — Registrations** (§5): index, form, workbench show.
4. **P4 — Credentials + Fraud** (§6, §7).
5. **P5 — Config: Categories, Bodies, Settings** (§8–§10).
6. **P6 — Analytics + export** (§11).
7. **P7 — Staff/Users + Self-profile** (§12, §13).
8. **P8 — Cross-cutting polish + completeness sweep** (§14, §15).
9. **P9 — QA, tests, docs** (§16).

## Consistency contract (applies to every admin page)
- Light background, faded doodle texture, **square corners**, 1px hairline borders.
- **Inter**, thin (200–400) body/headings, 500 uppercase labels, no bold-700.
- Medical-blue primary; status colours: verified=green, pending/info=blue, suspended/expired=amber,
  revoked=red, neutral=slate.
- One button system (`primary` solid blue / `ghost` bordered / `danger` red), square, weight 500.
- One table, one card, one badge, one form-field style — reused everywhere.
- Every destructive/irreversible action confirms; every mutation toasts the outcome.
- Nothing shown a role can't do.
