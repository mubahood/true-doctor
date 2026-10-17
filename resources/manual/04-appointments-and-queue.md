# Appointments and the queue

This chapter covers booking patients to see a doctor, running the day's check-in queue, and setting when each doctor can be booked. All three pages are in the sidebar under **Scheduling**: **Appointments**, **Check-in queue** and **Doctor availability**.

## Who can do what

| Role | What they can do |
|---|---|
| Hospital Admin | Everything, including setting doctor availability |
| Receptionist | Book, change, confirm, check in, cancel, mark no-show |
| Doctor | Book, check in, and record the outcome of an appointment |
| Nurse | See appointments, the queue and doctor availability |

Recording the outcome of an appointment means writing what was found, so only a Doctor or the Hospital Admin can do it. A Receptionist can check a patient in but cannot write the report.

## Set doctor availability first

A doctor can only be booked inside their availability. Each doctor has one or more **windows**: a day of the week, a start time, an end time, and a slot length. Only the Hospital Admin can add or change windows. Other staff can read the page to see who is free.

### Read the availability page

Open **Doctor availability**. At the top:

- **Doctors on the roster**: how many doctors have at least one window.
- **Clinic hours a week**: the total of all windows.
- **Appointment slots a week**: how many appointments the roster can hold.
- **Cannot be booked**: doctors with no availability at all. Click it to list them.

The **Cover** strip shows, for each day of the week, how many doctors are available. A day showing a dash has nobody, so no appointments can be booked that day.

Choose **Roster** to see one row per doctor and one column per day, or **List** to see one window per row. You can search by doctor, filter by day, show **Bookable only** or **Switched off only**, or tick **Not rostered**.

If two windows for the same doctor overlap on the same day, a warning appears and the windows are marked. Fix overlaps: booking uses whichever window it finds first, so the other window's slot length is ignored.

### Add a window

1. Press **Add window**. In **Roster** view you can also click an empty day cell for a doctor.
2. Pick the **Doctor**.
3. Pick the **Weekday**. You can choose one day, or **Every day**, **Weekdays · Mon to Fri** or **Weekend · Sat & Sun**. A repeating choice adds one window for each day it covers.
4. Pick one of the **Usual shifts** (Morning, Afternoon, Full day, Evening), or type **From** and **To** yourself.
5. Set the **Slot length (minutes)**: how long one appointment takes. For example, 15 minutes.
6. Pick a **Room** if the doctor always sits in the same one.
7. Leave **Active** ticked and press **Add window**.

To change a window, click it in the roster or press the pencil icon in the list. To stop bookings in a window for a while, for example while a doctor is on leave, click its **Active** badge in the list to switch it **Off**. Click again to switch it back on. To remove it for good, press the bin icon.

## Book an appointment

1. Open **Appointments** and press **Book**. You can also press **Book appointment** on a patient's record, which fills in the patient for you.
2. If this is a follow-up, pick the visit in **Following up on a visit**. The patient comes from that visit. For a new booking, leave it empty and pick the **Patient** by name, number or phone.
3. Pick the **Department** if you know it. This narrows the doctor list to that department.
4. Pick the **Doctor**.
5. Pick the **Source**: **Walk-in**, **Phone**, **Online** or **Referral**.
6. Choose **How long**: 15 min, 30 min, 45 min, 1 hour, 1½ hours, or type a number of minutes.
7. Under **Which day**, click a day. Days when the doctor has no availability are shown dimmed.
8. Under **What time**, click one of the free times. Only times the doctor is actually free are offered, and any time offered will book.
9. Pick a **Room** if needed, and write a **Reason**.
10. Press **Book appointment**.

If no times are offered, the form says why: no doctor chosen, the doctor does not work that day, or the day is fully booked at that length. Try a shorter appointment or another day.

For a booking months ahead, or at an hour outside the roster, open **Some other date or time** and type the date and time. The booking is still checked against the doctor's availability.

True-Doctor will not let you double-book a doctor or a room. A cancelled appointment or a no-show frees its time.

If your hospital has messages switched on and the patient agreed to care messages, the patient gets a text when the appointment is booked, and a reminder the day before. See [Setting up your hospital](02-setting-up.md).

## The appointment diary

**Appointments** shows the diary in two ways. Use the buttons at the top:

- **List**: one row per appointment with **When**, **Patient**, **Doctor**, **Room** and **Status**. Filter by date, doctor or status, or search by patient. Press **Clear** to remove filters.
- **Calendar**: one week, Monday to Sunday, with each appointment at its time. Use the arrows to move a week at a time. Click the dates between the arrows to come back to this week.

Click an appointment to see its quick view: the patient, doctor, department, room, how it was booked, the reason, and its **Trail** of status changes with who made each one.

### What the statuses mean

| Status | Meaning |
|---|---|
| **Scheduled** | Booked |
| **Confirmed** | The patient confirmed they are coming |
| **Checked in** | The patient has arrived |
| **In progress** | The patient is with the doctor |
| **Completed** | Seen, and the outcome is written up |
| **No-show** | The patient did not come |
| **Cancelled** | Called off |

An appointment moves forward through these steps. **Completed**, **No-show** and **Cancelled** are final.

### Move an appointment on

Each row has one main button for the next step, for example **Confirmed** or **Checked in**. The row's actions menu lists every allowed move under **Move to**.

Once the patient has checked in, the main button becomes **Record outcome** (see below).

### Change the time or room

Choose **Change time or room** from the row menu, or **Change** in the quick view. The booking form opens again. Pick the new day and time and press **Save changes**. The appointment's page (**Full record** in the menu) also has a **Reschedule** button.

### Cancel or mark a no-show

1. Choose **Cancelled** or **No-show** under **Move to**.
2. A small form asks **Why**. Pick one of the usual reasons, such as **Patient rang to cancel** or **Did not attend**, or type your own. It is optional, but it helps the next person who reads the record.
3. Press **Cancel it** or **Mark no-show**. Press **Keep it** to go back.

This cannot be undone.

## Record the outcome

An appointment is not finished by pressing a button. The doctor writes what was found and what the patient was given, and that becomes part of the patient's visit and bill.

1. Press **Record outcome** on a checked-in appointment.
2. Write the **Report**: history, examination, findings and plan. This is what the next clinician reads.
3. Under **What the patient was given**, add services from the price list (**Add a service**) and products from the pharmacy shelf (**Add a product off the shelf**). Set the quantity of each. The total is worked out for you.
4. To finish now, leave **Mark the appointment completed** ticked and press **Submit and complete**. To come back to it later, untick it and press **Save for now**.

If the appointment has no visit yet, True-Doctor opens one. The services and products go onto that visit's bill, and products come off the shelf. If nothing is added, the patient is charged only for what was already ordered on the visit. Taking payment is covered in [Billing and payments](07-billing-and-payments.md).

On the appointment's own page, a checked-in appointment also has **Open visit**, which opens a visit for the patient with the doctor, department and reason carried across. Work on the visit is covered in [Visits and consultation](05-visits-and-consultation.md).

## The check-in queue

**Check-in queue** is a live board of today's patients for the front desk and the nurses. It refreshes itself every 15 seconds while it is open. Press **Diary** to go back to the appointments list.

Four counts sit at the top:

- **Waiting**: arrived and not yet called, with the longest wait. The count changes colour as a warning after 20 minutes, and again after 45.
- **With the doctor**: being seen now.
- **Still expected**: booked for today and not arrived.
- **Seen today**: written up and closed.

Below are three lists:

- **With the doctor**: being seen now.
- **Waiting**: arrived and not yet called, longest wait first. The time shown is how long they have been waiting.
- **Expected**: booked and not arrived. It shows how late they are, or how long until they are due.

To show one doctor's patients, pick the doctor in the box above the lists. Press **Every doctor** to show everyone again.

### Work the queue

1. When a patient arrives, press **Check in** on their row. They move to **Waiting**.
2. When the doctor is ready to write up, press **Record outcome**, as described above.
3. Other moves, such as **No-show** or **Cancelled**, are in the row's actions menu under **Move to**.

The queue and the diary show the same appointments. A change on one appears on the other.
