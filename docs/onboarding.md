# Hospital setup (onboarding)

A new hospital must configure itself before the rest of the system opens up.
Setup is **mandatory** — there is no skip. A half-configured hospital cannot bill,
schedule or staff itself, and letting it in produces broken invoices and
unassignable work rather than a usable product.

## What is required

| Step | Passes when | Why the system needs it |
|---|---|---|
| Hospital details | An address, plus a phone number or email | Printed on every invoice, receipt, lab report and ID card |
| Currency and billing | A currency code and an invoice prefix | Every price and payment is stored in this currency; changing it later does not convert existing records |
| Departments | At least one active department | Appointments, visits and staff are organised by department |
| Price list | At least one **active** service | A visit cannot be invoiced until something is priced |
| Your team | At least one active colleague besides the owner | Each person signs in as themselves; work is attributed to whoever did it |

Three further steps are **recommended** and never block: consultation rooms,
wards and beds, and the lab/pharmacy catalogues.

Paying for the subscription is deliberately not a step. A hospital on its free
trial is fully operational; lapsed subscriptions are enforced separately by
`EnsureSubscribed`.

## How it is enforced

`App\Support\OnboardingStatus` is the single source of truth. It reads the
hospital's real data — nothing about completion is stored — so a step completed
anywhere in the system, or undone, is reflected immediately. Both the wizard and
the gate read it, so what the admin is told and what the system enforces can
never drift.

`OnboardingStatus::mustCompleteSetup($user)` answers the one question both the
gate and the sidebar ask: *is this person being held in setup right now?*

`App\Http\Middleware\RequireOnboarding` redirects to the wizard and is
deliberately narrow:

- only the admin who can configure (`manage-settings`) — never other staff,
  never a super-admin;
- only plain GET page loads, so the wizard's own Livewire round-trips and every
  form submission pass through untouched;
- the wizard, the module pages each step's data lives in (reachable from the
  setup-mode sidebar, not from a link inside the step itself — see below), and
  account essentials (subscription, notifications, sign-out) stay reachable —
  no redirect loop, no dead end;
- the hospital is read fresh, so finishing a step lifts the gate on the very
  next page.

`ONBOARDING_GATE=false` disables it (the test suite ships with it off; the
onboarding suites switch it on explicitly).

## What the sidebar shows while setup is outstanding

Every page outside the allow-list bounces back to the wizard, so offering the
full menu would be a menu of dead ends. While `mustCompleteSetup()` is true the
sidebar collapses to a single **Configuration** section containing the wizard
and the module pages the steps link to, in the order the wizard asks for them —
each one checked against `RequireOnboarding::ALLOWED`, so the menu can never
offer a link that would bounce. Above it, a short panel shows setup progress and
says why the menu is short. The brand link points at the wizard rather than the
dashboard for the same reason.

The sidebar is `@persist`-ed across `wire:navigate`, so it is persisted under a
*different key* (`sidebar-setup`) while setup is outstanding: the moment the last
required step passes, the key changes and the full menu renders on the very next
navigation instead of a stale one surviving.

Nobody else is affected — other staff at a half-configured hospital are not
gated, and keep the full menu.

## The wizard

`App\Livewire\Onboarding\Index` is a stepper, not a list of links. Every one of
its eight steps — required and recommended alike — expands into a small form
that writes through the same services and FormRequest rules the full modules
use, so there is no parallel implementation and nothing to keep in sync:

| Step component | Writes through |
|---|---|
| `Steps\Profile` | `HospitalProfileRequest::rulesFor()` → the hospital record |
| `Steps\Billing` | `BillingSettingRequest::rulesFor()` (currency subset) → `settings.billing` |
| `Steps\Departments` | `DepartmentRequest::rulesFor()`; one click adds the common set |
| `Steps\Services` | `ServiceRequest::rulesFor()`; the starter catalogue with editable prices |
| `Steps\Staff` | `StaffService::create()` — temporary password, queued welcome email, plan seat limit |
| `Steps\Rooms` | `RoomRequest::rulesFor()` (name/type/capacity subset); a small starter set |
| `Steps\Wards` | `WardRequest::rulesFor()` + `BedRequest::rulesFor()`; a ward and its starter beds are created together in one transaction — a ward with no beds cannot admit anyone |
| `Steps\Catalogues` | `LabTestRequest::rulesFor()` and `StockCategoryRequest::rulesFor()` — two independent lists in one step, since either alone satisfies it |

A step never links out to its full module page from inside its own body — that
used to sit right below the form doing the same job, offering two ways to do
one thing. The full page stays one click away in the sidebar (see above) for
work the inline form doesn't cover (assigning a room to a department, editing
a lab test's reference range).

**Suggestions are curated, not generated.** `App\Support\SampleCatalogue` holds
one hand-written, research-based starter set per resource — common East
African private-hospital pricing tiers for services and wards, common
department and room names, common lab tests — the same catalogue the full
module pages import from, so a hospital sees the same defaults wherever it
asks for them. The price list's set spans real specialties, not just general
medicine — General & outpatient, Maternity & gynaecology, Dental, Surgery,
Paediatrics, Eye/ear/nose/throat, Emergency — and the Services step groups its
suggestion tables by that same `category`, so a hospital actually sees the
breadth on offer instead of one flat, undifferentiated list. Every suggestion
table is **dynamic**: each row carries a
`removeSuggestion()`/`x-ui.icon-button` control that drops it from the preview
before anything is written, and the underlying `#[Computed] suggestions()`
re-filters live, so dismissing one reveals the next candidate rather than just
shrinking the list. Rows that take an editable value (a service's price, a
ward's bed count and daily rate) stay editable right up to the moment the batch
is added.

**Already-added rows are editable and removable in place.** Departments,
Services, Rooms, Wards and both Catalogues lists show what already exists as a
table with a pencil and a trash icon on every row — a popup (`<x-ui.modal>`) to
edit, `wire:confirm` before deleting. These go through the resource's own
policy (`AuthorizesRequests` — `DepartmentPolicy`, `ServicePolicy`, and so on),
not `authorizeSetup()`: a precise mutation on an existing record deserves the
same gate the full module page already uses, not the wizard's looser
"can configure this hospital" check the quick-add actions use. A guard that
exists on the full page is mirrored exactly — a ward with beds, or a stock
category with items, refuses the delete with the same message. Staff is
deliberately excluded: removing or editing a colleague's account is a more
sensitive action (authentication, role, audit attribution) than a catalogue
row, and stays the full Staff page's job.

Each step dispatches `onboarding-updated`; the shell's `refresh()` re-reads the
checklist so its status catches up immediately — but it does **not** move the
admin on. Finishing a step reveals a "Next" button in that step's own body
(rendered only once `$step->done`); moving to the next one is the admin's
decision, not something that happens to them. `next()` opens whichever step
follows the current one in the list, done or not. The open step is
deep-linkable (`?step=services`).

The page shows, at all times: a progress bar over the required steps, a "still
needed" list of what is blocking — each entry a real `<button class="wz-chip">`
that jumps straight to that step, not the passive `.badge-tb` span used for
read-only status pills elsewhere — each step's current state ("3 active
departments", "Only your own account"), and any issues worth fixing even where a
step passes ("Tax is switched on but the rate is 0%").

Staff without `manage-settings` see the checklist read-only.

## Accessibility

Each step is a `<section>` with a heading; the toggle carries `aria-expanded`
and `aria-controls`. Progress is a `role="progressbar"` with `aria-valuetext`
("3 of 5 complete") and the same figure in visible text, so nothing depends on
colour. The rail marks the open step with `aria-current="step"`, and every status
badge has a text label rather than colour alone.

## Adding a step

1. Add the check and a `Step` to `OnboardingStatus::steps()`; add its key to
   `REQUIRED` only if the system genuinely cannot work without it.
2. For an in-place form, add a component under `App\Livewire\Onboarding\Steps`
   using the `InteractsWithSetup` trait, validating with the module's existing
   FormRequest, and call `stepCompleted()` on success.
3. Wire it into the `@switch` in `resources/views/livewire/onboarding/index.blade.php`
   — the step's own inline form, never a link to the full module page.
4. If the step's data has a full module page of its own, allow-list that route
   in `RequireOnboarding::ALLOWED` so it stays reachable, and add it to
   `$setupOrder` in `resources/views/partials/admin-nav.blade.php` so the
   setup-mode sidebar offers it.
5. If admins will want a one-click starter set, add it to
   `App\Support\SampleCatalogue` (research real, sensible defaults — this
   is shared with the full module pages' own importer) and give the step an
   `excluded` array + `removeSuggestion()` so every suggestion stays dismissible.
6. If the resource can be safely edited/removed once added (i.e. it isn't a
   sensitive record like a staff account — see above), add `AuthorizesRequests`,
   an `edit($id)`/`saveEdit()`/`delete($id)` trio gated by the resource's own
   Policy, and an `<x-ui.modal show="showEdit">` popup — mirror any
   `assertDeletable()` guard the full module's `Index` component declares, so
   the wizard can never allow something the full page would refuse.
7. Make sure `DemoSeeder` satisfies the new requirement — `SmokeRoutesTest`
   fails if the demo tenant stops being fully onboarded.
