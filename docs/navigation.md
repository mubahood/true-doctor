# The back-office menu

One file defines the whole sidebar:
[`resources/views/partials/admin-nav.blade.php`](../resources/views/partials/admin-nav.blade.php).
`tests/Feature/SidebarMenuTest.php` holds it to the rules below.

## It is ordered the way a day runs

```
  [ brand mark ]                ← the dashboard lives here, not in a row below

  PATIENT CARE                  ← most of the day first
    Visits                          the spine — and the way in
    Scheduling ▾                    book them · check them in · see who is free
    Diagnostics ▾                   lab orders · radiology orders
    Pharmacy ▾                      stock items · stock alerts
    Inpatient ▾                     occupancy board · admissions
    Patients                        the register — reference, not daily work

  BILLING & FINANCE             ← what the care above turns into
    Billing ▾                       invoices · insurance claims
    Finance ▾                       reports · financial years

  ADMINISTRATION                ← set up once, edited rarely
    Hospital ▾                      departments · rooms · wards · beds · staff profiles · user accounts
    Catalogues ▾                    price list · lab tests · radiology studies · stock categories · insurance providers
    Settings ▾                      set up your hospital · billing settings · subscription
```

A super-admin gets a **Platform** section above Patient care (hospitals, plans,
subscriptions, site settings) — for them it is the job, not an aside.

**The dashboard has no row.** The brand mark at the top of the sidebar is that
link, so a row below it would be a second control for one destination. The menu
therefore opens on the work.

Reading downwards answers "where do I start, and what comes next", which is the
whole point: the order is the working day, not the order the modules were built
in.

Inside Patient care that means **Visits first and the patient register last**.
Everything a patient does hangs off a visit ([`visits.md`](visits.md)), and the
Visits page opens one either way — pick a registered patient, or take a walk-in
and register them on the spot in a single transaction
(`VisitService::intake`). The register below is where you go when someone new
arrives outside a visit, or a phone number is wrong: real work, but not work you
do every time you see a patient. Putting it at the top would have made the first
thing in the menu the thing you touch least.

## Four rules

**1. Work and setup never share a group.** "Lab orders" is a queue that changes
by the hour; "Lab tests" is a list edited twice a year. They used to sit side by
side under *Laboratory*, styled identically, so a lab technician had to know
which was which. Every catalogue, every list and every settings page now lives
under **Administration**; the care sections hold only today's work.

**2. The label is the page title.** Click "Lab tests", land on a page headed
"Lab tests". Where the two had drifted, the *page* was renamed, not the menu —
`Users` became **User accounts** (so it stops colliding with *Staff profiles*
one row above) and `Settings` became **Site settings**.

**3. A menu entry's gate is its page's own gate.** Each `can` mirrors the
destination's `Policy::viewAny` or `abort_unless`. A wider menu offers 403s; a
narrower one hides pages the user is allowed to use. Both teach people that the
menu lies. The one deliberate exception is *Set up your hospital*, gated on
`manage-settings` although the page renders read-only for everyone — a setup
chore is not worth advertising to a pharmacist.

Both directions are tested, for **every** role, not just the hospital admin —
that one holds every permission, so a gate that is too narrow looks perfect from
the top while hiding a page from everyone else.

Aligning menu and page exposed one genuinely wrong gate the other way round:
`DoctorSchedulePolicy::viewAny` was `access-admin`, so any signed-in staff member
could open the availability board by typing the URL while the menu offered it
only to `schedules.manage` holders. It is now `appointments.view` — whoever books
needs to know who is free — which also puts the board in doctors', nurses' and
receptionists' menus for the first time, read-only.

**4. One destination, one control.** The dashboard is reached by the brand mark;
nothing in the menu duplicates it. Equally, nobody expands a folder to find one
thing: a group that gates down to a single visible child renders as that
child instead. A lab technician sees a flat **Lab orders**; a doctor, who also
has radiology, sees **Diagnostics ▾** with both. This falls out of the permission
filter, so it needs no special-casing per role.

## Three ranks, three weights

| Rank | Looks like | Example |
|---|---|---|
| Section | tiny grey capitals, a hairline above, not clickable | `PATIENT CARE` |
| Destination | icon chip, small capitals — link *or* group header | `VISITS`, `DIAGNOSTICS ▾` |
| Sub-destination | sentence case on a rail, indented | `Lab orders` |

A link and a group header sit at the same rank and look the same on purpose: the
caret is then the only difference between them, and it means exactly one thing —
*this one opens*. Because it carries that whole distinction, the caret is drawn in
the same token as the label it sits beside, not in the lighter icon grey; and a
group never repeats one of its own children's names, or the two ranks would be
indistinguishable at a glance (hence **Scheduling ▾ → Appointments**, not
*Appointments ▾ → Appointments*).

Each section is its own `<ul>` labelled by its heading, so a screen reader
announces the same grouping the eye sees.

Only one group is open at a time (`tdNav` in `resources/js/admin.js`), the open
one is remembered across navigations via `Alpine.$persist`, and the group owning
the current page opens itself.

After each navigation `tdNav.syncActive()` re-derives the current entry by taking
the **longest** menu href that prefixes the URL. That rule is why the dashboard
cannot be a menu row: its href *is* the app root, so it prefixes every admin URL
and would light up as "you are here" on every page the menu does not list — a
notification, a dispensation. It was a row briefly and did exactly that.

## During setup

While `OnboardingStatus::mustCompleteSetup()` is true the whole menu collapses to
one **Configuration** group — see [`onboarding.md`](onboarding.md). Every page
outside `RequireOnboarding::ALLOWED` would bounce back to the wizard, so offering
it would be a menu of dead ends.

That group is keyed `setup-config`, deliberately not `config`. The open group is
persisted by key, so sharing a key with the real Settings group would leave
Settings hanging open the moment setup finished — greeting the admin with the
chore they just completed at the exact moment they first see the real menu.

## Adding an entry

1. Add it to `$sections` in the partial, in the position the *work* belongs —
   daily work under Patient care or Billing & finance, anything set up once under
   Administration.
2. Set `can` to the destination's own authorisation. If you find yourself wanting
   a looser gate than the page, the page's gate is probably the thing that is
   wrong.
3. Make the label identical to the component's `->title(...)`.
4. If a setup step links to it, allow-list the route in
   `RequireOnboarding::ALLOWED` and add it to `$setupOrder`.
5. Run `SidebarMenuTest`. It will tell you if the page 403s for a role you
   offered it to, if a role can open the page but you did not offer it, if the
   label and the title disagree, or if you filed setup among the daily work.
