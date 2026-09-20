# Design system

Light-only, square, Inter. One stylesheet — `resources/css/admin.css` — built by Vite
into `public/build`. Never edit `public/build`.

## Tokens (`:root` in `admin.css`)
| Group | Tokens |
|---|---|
| Surfaces | `--bg`, `--surface`, `--surface-2`, `--elev` |
| Lines | `--line`, `--line-2`, `--bd` |
| Text | `--tx`, `--mt`, `--mt2` |
| Brand | `--br`, `--br-d`, `--br-soft`, `--ac` |
| Status | `--ok/--ok-soft`, `--warn/--warn-soft`, `--bad/--bad-soft`, `--info/--info-soft` |
| Layout | `--sw` (sidebar width), `--th` (topbar height), `--font` |

Rules: colours only via tokens (no hex in views); status colours only through
`<x-ui.badge>` / enum `badge()`; spacing via utilities, not inline styles.

## Kit (`resources/views/components/ui/`)
| Component | Purpose |
|---|---|
| `x-ui.link` | in-app link, always `wire:navigate` (`:navigate="false"` for downloads) |
| `x-ui.icon-button` | icon-only control with a mandatory `label` |
| `x-ui.page-header` / `x-ui.breadcrumb` | one `<h1>` + crumbs (`:sr="true"` on list pages) |
| `x-ui.field` | label + control + `@error` + hint (`for`, `name`, `required`, `hint`) |
| `x-ui.badge` | status pill (`tone` = active/success/info/warn/danger/neutral or a `badge-*` class) |
| `x-ui.empty` | empty state with `noun`, `:filtered` and an optional clear action |
| `x-ui.th-sort` | sortable header for `WithTable` |
| `x-ui.money` | tenant-formatted amount |
| `x-ui.progress` | accessible progress bar (`value`, `max`, `label`, `tone`); the value is announced and shown as text |
| `x-ui.modal` | the standard dialog: centred, focus-trapped, dirty-guarded, entangled to a boolean prop (`size` = sm/md/lg/xl/full) |
| `x-ui.sample-import` | starter-data import modal |
| `x-dash.stat/section/bars/donut/sparkline/quick/empty` | dashboard widgets |
| `livewire:ui.select-search` | async, tenant-scoped picker for large lists |
| `livewire.partials.pagination` | themed AJAX pagination (via `WithTable::paginationView()`) |
| `livewire.partials.skeleton` | `#[Lazy]` placeholder |

## Layout classes
`tb-detail-cols` (summary + workspace), `tb-grid-2`, `tb-stack`, `tb-panel`,
`tb-inline-form`, `tb-toolbar` / `tb-toolbar-actions`, `tb-card*`, `tb-table*`,
`tb-form-group` / `tb-label` / `tb-input` / `tb-select` / `tb-textarea` / `tb-field-error`,
`tb-modal-backdrop` / `tb-modal` / `tb-modal-head` / `tb-modal-body` / `tb-modal-foot`.

## Utilities
`tb-mt-2/3/4`, `tb-mb-4/5`, `tb-text-right/center`, `tb-nowrap`, `tb-flex`,
`tb-fw-500/600`, `tb-small`, `tb-xs`, `muted`, `mono`, `tb-danger/primary/ok`, `sr-only`.

## Auth pages (`resources/css/auth.css`)

Sign-in, registration and the password flows sit outside the SPA but use the
same language: square, flat, thin Inter, one blue accent, uppercase micro-labels.
They have their own small bundle because they load no Livewire or Alpine.

| Piece | Purpose |
|---|---|
| `.auth-shell` | the page grid — one column by default; `is-split` adds the 340px context rail, `is-wide` widens the form column for long forms |
| `.card` · `.af-eyebrow` · `.af-title` · `.af-sub` | the form card and its heading block (one `<h1>` per page) |
| `.a-grid` / `.span2` | two-column field grid, single column below 620px |
| `.a-field` · `.a-label` · `.a-inwrap` · `.a-input` · `.a-eye` · `.a-hint` | the field stack; `.a-input.has-eye` reserves room for the visibility toggle |
| `.a-btn` (`.ghost`) · `.a-alert` (`.ok` / `.err`) · `.a-alt` · `.a-secure` | actions, feedback, footer links, trust row |
| `.a-panel` (`-head` / `-body` / `-foot`) | a rail panel, styled like a `tb-card` |
| `.a-accts` / `.a-acct` | the local-only demo-account list (rows, not cards), fed by `App\Support\DemoAccount` |
| `.reg-trial` · `.reg-plans` / `.reg-plan-body` · `.reg-reassure` | registration trial banner, plan picker and reassurance row |

A page opts into the rail simply by yielding an `aside` section; the layout adds
`is-split` itself. Breakpoints: 1023px drops the rail under the form, 620px goes
full-width with 16px inputs and 46px tap targets.

## Motion & accessibility
120–220 ms transitions; `prefers-reduced-motion` disables them. Every dialog traps
focus; every icon-only control has an accessible name; every table action is
keyboard-reachable; the offline banner and toasts are `aria-live`.

## A row you can point at, and a record you can read without leaving

### The serial column

Every listing table starts with `#` — the row's number on the list, counting
THROUGH pagination, so row 21 on page two is #21 and not #1. A serial that
restarts is not one.

It is there to be pointed at, not looked at: a table of twenty-five
near-identical drug names is hard to talk about, "the third one" means nothing
once somebody sorts it differently, and reading a name back over a phone is how
the wrong thing gets ordered. `<x-ui.serial :rows="$rows" :loop="$loop" />`.

### Quick view, not a page away

A row shows what fits; the record has more, and the useful part is usually the
part that does not fit — how an appointment got to its status, how a shelf got
to twelve. That belongs over the list, not instead of it: opening a page loses
the place somebody is working down, and they were only asking one question.

So a listing row's own name opens a **quick view** — everything the row leaves
out, plus recent history, plus the actions, plus one link to the full record.
The pattern is in `Appointments\Index` and `Stock\Index`; `peek()` /
`closePeek()` / a `peeked` computed, and any dialog it opens CLOSES it rather
than stacking on top (`InsuranceProviders\Index::editPeeked()` is the shape).

The dialog earns its place by carrying the figure the row made somebody work
out in their head: what is still spendable on a card, whether an insurer's
float would clear what its members owe, how much of an invoice a claim covers.
A dialog that only repeats the row is a page-load for nothing.

Every list in the back office now has one, and the machinery is shared:
`PeeksRecords` (open, close, the record, the authorisation) or
`PeeksAndEditsRecords` where the row is editable too — a component names its
model, what to eager-load and any figures it caches beside the record. The
markup is `x-ui.peek-head`, `x-ui.peek-figs`, `x-ui.peek-trail` and
`x-ui.peek-foot`, so twenty dialogs are not twenty layouts to learn.
`QuickViewTest` holds every list to it.

**Where a page is still right.** A visit workspace, a patient record and an
invoice are not records you glance at — they hold panels and their own dialogs,
and people paste their URLs to each other. A modal for those would mean dialogs
inside a dialog and a record nobody can link to. Quick view where the row is a
RECORD; a page where it is a WORKSPACE, always one click away from the quick
view.
