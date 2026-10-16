# 03 · UI and density

The web back office and the phone app share one visual language. The web is the reference; the phone mirrors it (`lib/theme/td_tokens.dart` copies the web `:root` tokens).

## The look

Light only. Flat, square corners (no radius on controls, cards, dialogs). **Inter**. One accent: `--br #0A6EBD`. Status colours only through badges. Hairline borders (`--line`, `--line-2`). Shadows only on dialogs and floating menus. Text: `--tx` body, `--mt` muted, `--mt2` micro-labels (both muted greys were darkened to pass WCAG AA; don't lighten them).

## The compact rule (standing instruction from the owner)

Small buttons, small paddings, small margins, a slim sidebar whose items do not consume space, and still *very well organised*. Targets:

| Thing | Web (`admin.css`) | Phone (Flutter) |
|---|---|---|
| Body text | 12.5 px, line-height 1.45 | 13 px, 1.45 |
| Sidebar / drawer width | `--sw: 208px` | 208 |
| Topbar | `--th: 44px` | `TdSpace.topbar` 44 |
| Menu row | ~30 px (padding 4–5 × 12, 22 px icon chip) | ~30 px (3 × 10 padding, 22 px chip) |
| Button | padding 5 × 10 (`btn-tb-sm`: 3 × 8), 12 px text | 8 × 11 (small: 5 × 8) |
| Input | padding 5 × 9, 12.5 px | contentPadding 8 × 10 |
| Card header / body | 8 × 12 / 12 | 9 × 12 / 12 |
| Table cell | 5 × 10, 12 px | list rows 6 × 12 |
| Content gutter | 12 × 18 (10 × 12 on small screens) | 12 |
| Dialog | head 9 × 14, body 12 × 14, foot 8 × 14, backdrop pad 16 | inset 14, head/foot 8 × 14 |
| Page title | 20 px | 20 px |

Keep phone **tap targets near 36 px**: the phone is used one-handed, standing, sometimes with gloves. Compact does not mean fiddly.

How to stay organised while dense: group with hairlines and small uppercase captions; align labels and values in columns; use badges, not paragraphs; put the primary action top-right of the card; show secondary actions in the row menu; use the quick view instead of navigating away; use tabs for a record with many facets (the patient page).

**Do not** add new spacing values. Use the existing utilities (`tb-mb-3/4/5`, `tb-stack`, `tb-grid-2`) and tokens. If you must change density globally, change the tokens/sources in `admin.css` and `td_tokens.dart`, then rebuild and run the CSS guard.

## Web kit

Authoritative list and class names: [`../design-system.md`](../design-system.md) and [`../livewire-conventions.md`](../livewire-conventions.md). Essentials:

- `x-ui.link` (always `wire:navigate`), `x-ui.page-header`, `x-ui.field`, `x-ui.badge`, `x-ui.empty`, `x-ui.money`, `x-ui.modal`, `x-ui.serial`, `x-ui.peek-*`, `livewire:ui.select-search`, `x-dash.*`.
- **Dialogs are centred modals**, never side drawers; dirty-guarded; full-screen sheets on phones.
- **Every list** starts with a `#` serial column counting through pagination, and its row name opens a **quick view** (`PeeksRecords`). Workspaces (a visit, a patient record, an invoice) are pages, because people paste their URLs.
- Feedback: `dispatch('toast', …)` in place; `session()->flash` before a navigate redirect; never both.
- One `<h1>` per page, server-rendered title.
- **The SPA is `wire:navigate`.** No hard links, `window.location`, native dialogs, `<form method="POST">` in Livewire views (`SpaNavigationHtmlTest` enforces).
- Shell JS is `resources/js/admin.js`; component JS uses `@script`/`@assets`, never `DOMContentLoaded`, and every listener has teardown.
- Colours via tokens only; no hex in views.

### Guards that fail loudly
`DesignSystemCssTest` fails if a view uses a class the CSS does not define, if built assets are older than the stylesheets (**run `npm run build`**), if a component is used without the classes that style it, or if the grouped-form spacing rule is broken. `QuickViewTest`, `SpaNavigationHtmlTest` and `GeneratedDocumentTest` also guard UI.

## Phone kit (`lib/widgets`, `lib/theme`)

| Piece | Use |
|---|---|
| `td_tokens.dart` | `TdColors`, `TdSpace`, `TdText` — mirror of the web tokens. Colours only from here |
| `td_theme.dart` | Material theme set to the flat look; buttons/inputs/nav sized per the table above |
| `td_widgets.dart` | `TdCard`, `TdRow`-like rows, badges, alerts (`TdAlert`), empty states, buttons |
| `td_list.dart` | `TdPagedQuery` (paged, searchable, **offline-narrowing**), `TdListShell`, `TdRow` |
| `td_forms.dart` | `FormFrame`, form footer, fields, pickers. Forms own their result callbacks so closing mid-save never loses it |
| `td_feedback.dart` | `tdToast`, `tdConfirm`, `tdPrompt`, `showTdSheet`/`showTdPage`/`showTdChoice`, `TdSkeleton` |
| `fa_icons.dart` | Generated from `tool/app_icon_names.txt` by `tool/generate_fa_icons.py`. Add the name to the txt, regenerate; `test/theme/icons_used_test.dart` guards it |

Rules: no native `showDialog`/`SnackBar` directly — use the kit. Every list is a `TdListShell` + `TdPagedQuery` so it gets search, paging, skeleton, the offline copy and the stale banner for free. Text scale is clamped to 1.3 in `main.dart`; make layouts survive it. Never rely on colour alone (WCAG AA).

### Looking at what you built
Do not claim a screen looks right without seeing it.

- Phone: `TD_SHOTS=<dir> flutter test test/screenshots/shots_test.dart` renders PNGs; open them.
- Web: run the app (`http://localhost:8888/true-doctor`) and look, at desktop and ≤ 900 px width. If you could not look, **say so**.

## Copy

Plain, short, specific. "Owes USh 25,000 — oldest unpaid 10 days" beats "Outstanding balance exists". Errors say what happened and what to do. Labels are sentence case; micro-labels are uppercase by CSS, not by typing. The words follow the practice (a dental clinic sees "Drugs inventory", not "Pharmacy" — `app/Support/Practice`).
