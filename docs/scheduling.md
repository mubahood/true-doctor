# Scheduling (Phase 1, Step 9)

Template-driven appointment booking with conflict detection and an explicit
appointment state machine (HMS_PLAN.md §4, B7). All tenant-scoped via
`BelongsToHospital`.

## Files

| Concern | File |
|---|---|
| Tables | `2026_07_19_140000_create_doctor_schedules_table.php`, `…_140010_create_appointments_table.php`, `…_140020_create_appointment_status_histories_table.php` |
| Models | `app/Models/DoctorSchedule.php`, `app/Models/Appointment.php`, `app/Models/AppointmentStatusHistory.php` |
| Enums | `app/Enums/Weekday.php`, `app/Enums/AppointmentSource.php`, `app/Enums/AppointmentStatus.php` |
| Service | `app/Services/AppointmentService.php` (booking, reschedule, transitions) |
| Exceptions | `app/Exceptions/SlotUnavailableException.php`, `app/Exceptions/InvalidStatusTransitionException.php` |
| Requests | `app/Http/Requests/{DoctorSchedule,Appointment,AppointmentTransition}Request.php` |
| Policies | `app/Policies/{DoctorSchedule,Appointment}Policy.php` |
| Controllers | `app/Http/Controllers/Admin/{DoctorSchedule,Appointment}Controller.php` |
| Views | `resources/views/admin/{schedules,appointments}/…` |
| Routes | `schedules` resource + `appointments` resource + `appointments/queue` + `appointments/{a}/transition` |

## Availability templates

`doctor_schedules` holds a doctor's weekly windows (`weekday` 0–6 matching Carbon's
`dayOfWeek`, `start_time`–`end_time`, `slot_minutes`, optional `room_id`). A day may have
several windows. Managed by roles with `schedules.manage` (hospital admin).

### The availability page reads the week as a week

`/admin/schedules` offers two readings of the same windows, chosen in the toolbar and
remembered in the URL (`?view=`):

- **Roster** (default) — one row per *doctor*, seven day columns, every window in its day.
  It paginates over doctors, not windows: the row is the doctor, and a doctor split across
  two pages is not a roster. An empty cell is an add button that arrives with that doctor
  and that day already filled in (`addFor`).
- **List** — one window per row, sortable on day, start, end, slot length and status, with
  the number of appointments each window holds.

Above both sits what the week comes to — doctors on the roster, clinic hours, appointment
slots a week, and **how many doctors have no availability at all**, which is the figure
that decides whether they can be booked and which nothing used to show. Those four are
hospital-wide facts, deliberately *not* narrowed by the filters: a count that moved as you
typed in the search box would be describing your search. Beneath them, a strip of the
seven days showing how many doctors cover each; a day reading `—` takes no appointments.

**Overlapping windows are flagged, in both views.** `assertBookable` walks a doctor's
windows and takes the first that contains the requested time, so where two windows overlap
the other one's `slot_minutes` is silently ignored — and the person booking gets an
alignment error naming a number they cannot see anywhere on the page. Overlaps are
reported rather than refused: the data can already hold them, and the fix is a judgement
about which window is wrong. Both ends of an overlapping pair are marked, since either
could be the mistake.

### The booking dialog asks for a day, then a free time

`scheduled_at` is one datetime-local value but it answers two questions that
narrow each other, so the dialog asks them separately:

1. **How long**, first — the length decides which times fit. A 15-minute gap is
   free for a 15-minute appointment and not for an hour one.
2. **Which day** — a fortnight of named days ("Today", "Tomorrow", then `D j M`).
   Days the chosen doctor has no active window on are dimmed but still
   clickable: a doctor can be asked to come in specially, and the dialog should
   not forbid what the service allows.
3. **What time** — the doctor's *actual* free times for that day, at that
   length, generated the way `assertBookable` checks them: the window's own
   `slot_minutes` grid, the whole appointment inside one window, nothing
   overlapping a booking that still occupies its slot (so a cancelled one gives
   its time back), and nothing already in the past. **A time that is offered is
   a time that books** — `AppointmentWhenTest` pins that contract by booking
   every offered time through `AppointmentService`.

Choosing a day lands on the first free time rather than leaving the second
question open; a time that does not survive a change of day or length is
replaced rather than carried into a booking that would be refused on save. When
there is nothing to offer, the gap says which thing is missing — no doctor
chosen, no day chosen, "Dr X has no availability on Fridays", or "fully booked
at 60 minutes, try a shorter appointment". A `<details>` holds the plain
datetime box for a booking months out or at an hour the roster does not
describe.

### The diary is a list or a week, and is changed from the row

`/admin/appointments` offers two readings of the same filtered set, chosen in
the toolbar and remembered in the URL (`?view=`). One `filtered()` query feeds
both, so they cannot drift about what is in the diary; the calendar ignores
`date` because the week it draws *is* the date.

- **List** — sortable on time and status. The **When** column carries the day
  ("Today", "Tomorrow", "Fri 18 Sep") above the hour: with no date filter the
  list spans the whole diary, and `09:30–10:15` on its own does not say which
  09:30.
- **Calendar** — the week Monday to Sunday, each appointment a block at its
  time, coloured by status so the week reads without a legend. Stepped a week
  at a time. Clicking a block opens the quick view.

**Reading one no longer means leaving the page either.** The When cell and a
calendar block open a **quick view**: everything the detail page says — patient
and patient number, doctor, department, room, how it was booked, the visit it
follows, the reason, why it ended — plus the status trail, which is the part a
list cannot show at all and the commonest reason for opening the record. It
carries the same actions as the row, and a link to the full page for anything
further. Reading takes `view`, so a nurse may open it; the actions inside it
still take `update`.

**Changing one no longer means leaving the page.** The row carries the next
legal step as a button (Confirm, Check in, Start…) and a menu for the rest;
`edit()` re-opens the *booking* dialog prefilled, so a reschedule gets the same
day strip and the same list of times the doctor is really free. The slot list
ignores the appointment being moved — leaving it where it is has to stay one of
the answers, exactly as `assertBookable`'s `$ignoreId` does. Terminal
appointments cannot be moved; the dialog says so rather than opening.

### Completing one means saying what was done at it

`Completed` used to be a button. It moved a status and recorded nothing — no
findings for whoever sees the patient next, no services, nothing to bill. A
hospital could see patients all day and invoice none of it.

An appointment now ends the way every other piece of clinical work in this
system ends (docs/orders.md). `AppointmentService::recordOutcome()`, in one
transaction:

1. resolves the appointment's **visit**, opening one if it has none
   (`visitFor()` — `visits.appointment_id` is unique, so an appointment is only
   ever seen in one visit);
2. places an **Order** of type `Consultation` on it;
3. saves the doctor's **report** onto that order — the same `report` field a lab
   or imaging order carries;
4. adds **what the patient was given** as order items — a SERVICE off the price
   list or a PRODUCT off the pharmacy shelf, each priced from its own
   catalogue. Both go straight onto the visit's charges and therefore its
   invoice; a product also leaves the shelf, in the same transaction as the
   money, which is the order model's job and not this one's;
5. completes the order, then walks the appointment to `Completed` — through
   `InProgress` if it was only checked in, because the patient WAS with the
   doctor and the trail should say so.

**It does not have to be done in one sitting.** `recordOutcome(..., complete:
false)` saves the write-up and leaves the appointment with the doctor; the
dialog's "Mark the appointment completed" box is that flag. A second call finds
the consultation it already started rather than opening another, so one
attendance is always one consultation. The lines passed are the whole list, so
taking one off between sittings cancels it — and a cancelled product's stock
goes back. Cancelled lines are skipped when matching, or a line the doctor took
off would quietly return the next time they added the same thing.

Quantities are **whole units**. You give two tablets or one dressing; the
spinner used to step by a hundredth, so one press turned 1 into 1.01 and a
UShs15,000 visit billed UShs15,300.

None of that machinery is new. This is the appointment being made to use the
visit module rather than keeping a private idea of "finished".

**The rule is enforced in `transition()`, not on a screen.** A move to
`Completed` is refused unless the appointment's visit carries a completed
consultation order, so no screen, controller or API can complete an empty one.
The diary's Complete button and the detail page's both lead to the outcome
dialog instead. `recordOutcome` re-reads the appointment under a lock rather
than trusting the instance it was handed, because a caller that transitioned it
a moment ago still holds the status it had before.

**Who may.** Booking, confirming and checking in take `appointments.manage`.
Writing the report takes `visits.diagnose` on the visit — a receptionist can
check a patient in and cannot say what was found, which is the point.

Cancel and no-show do **not** go straight through: they open a small dialog
asking why, with the usual reasons as one click each. The reason is optional but
kept, because the next person to read the record needs to know whether the
patient rang or simply did not come. `AppointmentService::transition` remains
the only thing that moves an appointment, so an illegal jump is refused
whatever asks for it.

### The check-in queue is a board

`/admin/appointments/queue` was a table of today's rows: a booked time, a status
badge, and every legal transition drawn as its own button — four to a row,
including a `Completed` that could no longer do anything. Somebody who arrived
at 08:55 and had been sitting forty minutes looked exactly like somebody who had
just walked in, which is the one thing a queue has to be able to tell you.

It is now three lanes, in the order a desk thinks in:

- **With the doctor** — being seen now.
- **Waiting** — arrived and not yet called, **longest wait first**, because a
  queue is judged by whoever has been in it longest. The wait is measured from
  `checked_in_at`, not from the booking: a booked time is what somebody was
  promised, not what happened to them. It tints at 20 minutes and again at 45.
- **Expected** — booked and not arrived, showing "45m late" or "in 1h 30m".

Above them, the four numbers a desk is asked for across the counter — waiting
(with the longest wait), with the doctor, still expected, seen today — and a
doctor picker for a desk running several clinics at once.

Each row offers **one** action, named for what the desk is doing: *Check in* for
somebody who has not arrived, *Record outcome* for somebody who has. Everything
else is in the row menu.

### One vocabulary for both boards

The diary and the queue draw the same day in two shapes, so what can be DONE to
an appointment lives in `App\Livewire\Appointments\Concerns\ActsOnAppointments`
and the two dialogs in `livewire/appointments/partials/act-dialogs.blade.php`:
`advance()`, the cancel/no-show reason dialog, and the outcome dialog. Held once
because a second copy is exactly how the queue ended up still offering a bare
"Completed" button after the diary had stopped. `CheckInQueueTest` asserts both
components use the trait, so a third listing cannot quietly grow its own idea.

The host supplies two hooks: `beforeActingOnAppointment()` (the diary closes its
quick view so a dialog does not land on top of it) and
`afterActingOnAppointment()` (each page drops whatever it caches about the rows
it draws).

## Booking & conflict detection

`AppointmentService::book()` runs inside a DB transaction that:

1. **Locks** the doctor's availability rows for the requested weekday (`lockForUpdate`) —
   this both looks up the template *and* serializes concurrent bookings for that doctor, so
   overlap checks cannot race into a double-booking.
2. Requires the requested `[start, end)` to fall entirely inside one active window and the
   start to align to the window's `slot_minutes` grid (else `SlotUnavailableException`).
3. Locks the room row (if any) and rejects any overlapping **occupying** booking for the
   same doctor *or* the same room. "Occupying" = every status except `cancelled`/`no_show`
   (`AppointmentStatus::occupiesSlot()`), so a cancelled slot frees the time.

`scheduled_at` and `ends_at` are both stored (ends_at derived from duration) so overlap is a
plain indexed range query: `scheduled_at < :end AND ends_at > :start`. `reschedule()` re-runs
the same validation, excluding the appointment being moved.

## State machine

`AppointmentStatus` is the single source of truth for legal moves
(`transitionsTo()` / `canTransitionTo()`):

```
scheduled → confirmed | checked_in | cancelled | no_show
confirmed → checked_in | cancelled | no_show
checked_in → in_progress | cancelled | no_show
in_progress → completed | cancelled
completed / no_show / cancelled → (terminal)
```

Only `AppointmentService::transition()` moves an appointment: it locks the row, checks the
machine, stamps the matching timestamp (`checked_in_at`/`started_at`/`completed_at`/
`cancelled_at`), and appends an **append-only** `appointment_status_histories` row (initial
booking recorded as `null → scheduled`). An illegal jump throws
`InvalidStatusTransitionException`.

## Check-in queue

`appointments/queue` shows today's not-yet-finished appointments in time order, each with
its legal next-status buttons — the receptionist/nurse working view.

## RBAC

`schedules.manage` (hospital admin) · `appointments.view` (admin, doctor, nurse,
receptionist) · `appointments.manage` (admin, doctor, receptionist). Enforced by the two
policies; tenancy by the global scope.

Reading the availability board takes `appointments.view` — whoever books needs to see who
is free. It used to take only `access-admin`: the sidebar entry was gated on
`schedules.manage` (hospital admin only), but any signed-in staff member could open
`/admin/schedules` directly. Doctors, nurses and receptionists now get the board in their
menu; it stays read-only for them, since every write takes `schedules.manage`.

## Tests

`AppointmentServiceTest` (template/alignment/overlap/room-overlap/cancel-frees-slot + the
full state machine incl. illegal jumps & terminal states); `AppointmentBookingHttpTest`
(book → advance, double-book flash, illegal-transition flash, nurse view-not-book);
`AppointmentTenancyIsolationTest` (§2.1 A-vs-B, cross-hospital doctor rejected);
`CheckInQueueTest` (waiting time from arrival, the three lanes and their order,
the tally, the doctor filter, what each row offers, and both dialogs working
from the board); `AppointmentOutcomeTest` (the consultation order and its report, services
priced from the list and reaching the visit's charges, the trail through
InProgress, one visit per appointment, the guards before arrival and after the
end, rollback on a bad line, the dialog's own behaviour, and who may write a
report); `AppointmentDiaryTest` (the week and its filters, the quick view and its trail, editing from the row incl. a
refused clash and a terminal appointment, the status machine from the row, the
cancel-reason dialog, tenancy and RBAC); `AppointmentWhenTest` (the day strip, slot generation against the window grid,
bookings and cancellations, past times today, the two questions narrowing each
other, the empty-state notes, rubbish input, and the offered-times-always-book
contract); `DoctorRosterTest` (the headline figures, day coverage, overlap detection incl. touching
windows, a swallowed window and a third window measured against the longest so far; the
filters; adding from a cell; switching a window on and off; and that none of it crosses a
tenant boundary).
