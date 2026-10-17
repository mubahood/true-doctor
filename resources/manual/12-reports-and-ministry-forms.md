# Reports and ministry forms

This chapter is for hospital owners, administrators, accountants, records officers and in-charges. It covers the **Reports** page, the Ministry of Health forms (HMIS 105, 108 and 033b), the **Diagnoses to code** list that keeps those forms complete, the suggestions True-Doctor makes about what needs attention, and the owner's daily summary.

Every figure here is read from your live records: payments, invoices, visits, admissions, stock and the patient register. Nothing is typed in twice, so the better your daily records, the better your reports.

For the books themselves (statements, the journal and financial years), see [Finance](13-finance.md).

## Who can see what

| Page | Who |
|---|---|
| **Reports** | Hospital Admin, Accountant |
| **Ministry reports (HMIS)** | Hospital Admin, Accountant |
| **Diagnoses to code** | Doctor, Records Officer, Hospital Admin |
| **My daily summary** | Hospital Admin only, for themselves |

**Reports** and **Ministry reports (HMIS)** are in the **Finance** group of the menu, under Billing and finance. **Diagnoses to code** is under Patient care.

## The Reports page

Open **Reports** from the menu. The page is titled **Reports & dashboards**.

### Choosing the period

1. Press one of the period buttons: **Today**, **This week**, **This month**, **Last month**, **This quarter** or **This year**.
2. Or set any dates you like in **From** and **To**.

The page opens on this month. The period is kept in the page address, so you can bookmark a report or send the link to a colleague who also has access.

### What the page shows

The top row gives the headline figures:

- **Revenue received**: money paid in during the period, and the number of payments.
- **Outstanding**: what is owed on unpaid invoices, and how many invoices.
- **Inpatient nights**: nights billed in the period, and the amount billed.
- **Bed occupancy**: how many beds are full.
- **Stock value**: the value on the shelf, with how many items are low and how many are expiring.
- **Patients**: how many patients are on the register.

Some figures are marked "as at today". **Outstanding**, **Bed occupancy**, **Stock value** and **Patients** describe the position now. They do not change when you change the dates.

Below the headline row:

- **Money received, day by day**: a line showing which days the money came in.
- **Revenue by method**: cash, mobile money, prepaid card, bank transfer, insurance and the other payment methods.
- **Outstanding by age**: unpaid balances grouped by how long they have been owed. This is also a standing position, as at today.
- **Top services by revenue**: the ten services that earned the most, with how many times each was charged.
- **Doctor activity**: visits and appointments for each doctor in the period.
- **Inpatient nights by ward**: nights and amounts billed per ward, and how many stays were discharged.
- **Patients on the register**: patients by sex and by age group.

Many cards are links. Click **Outstanding** to go to the invoices, **Bed occupancy** to go to the occupancy board, and so on.

Inpatient figures only mean something if your hospital admits patients. A clinic with no wards will see zeros there.

### Printing the report

Press **Print report**. A PDF of the same period opens in a new tab, ready to print or attach to a board paper. The printed report lists the top 20 services instead of 10.

## Ministry reports (HMIS)

Open **Ministry reports (HMIS)** from the menu. True-Doctor fills in three Ministry of Health forms from your own records:

| Form | Period | What it counts |
|---|---|---|
| **HMIS 105** | Monthly | Outpatients: new attendances, re-attendances, and each diagnosis line of the form |
| **HMIS 108** | Monthly | Inpatients: admissions, discharges and deaths, with bed days, absconded patients and the diagnoses of those discharged |
| **HMIS 033b** | Weekly, Monday to Sunday | Cases and deaths of each notifiable condition |

### Producing a form

1. Choose the form in the first box: **HMIS 105 · outpatients (monthly)**, **HMIS 108 · inpatients (monthly)** or **HMIS 033b · surveillance (weekly)**.
2. For 105 and 108, choose the month. The page opens on last month, which is the one you usually report.
3. For 033b, pick any day in the week you want. The page shows the week number and its dates.
4. Check the figures on screen and the **Missing data** list below them (see the next section).
5. Press **PDF** for a copy laid out like the paper form, or **CSV** to open the figures in a spreadsheet.

### How the counting works

- Each outpatient visit is counted once, under its **primary coded diagnosis**. A diagnosis written only in words is not counted, because True-Doctor will not guess a code.
- A visit counts as a **New attendance** if it is the patient's first visit at your facility, and as a **Re-attendance** otherwise.
- Counts are split by sex and by the form's age bands: 0 to 28 days, 29 days to 4 years, 5 to 9 years, 10 to 19 years, and 20 years and over.
- On the 105, codes that do not belong to any line of the form are added up under "All other diagnoses". The line under the table also gives the total visits for the month.
- On the 033b, a row with cases is highlighted so you can see at a glance what to report.

The form version in use is printed in the explanation at the top of the page.

### Fixing missing data

Under the 105 and 108 tables, **Missing data** lists every visit that could not be counted and why: no coded diagnosis, no age, or no sex. Each row has a link to fix it:

- **Code it** opens the visit so a clinician can add the coded diagnosis.
- **Fix the patient** opens the patient's details to add the date of birth or sex.

When the list says "Nothing missing: every visit is counted.", the form is complete. Work through this list before you submit each month. Most of it is cleared faster from **Diagnoses to code**.

## Diagnoses to code

Doctors often write a diagnosis in words and move on to the next patient. The Ministry forms only count coded diagnoses, so **Diagnoses to code** collects every visit whose diagnosis was written but not coded.

For each visit you see the visit number and date, the patient, the written diagnosis and, under **Codes that may fit**, the codes True-Doctor suggests from the words.

1. Open **Diagnoses to code** from the menu.
2. Read the written diagnosis.
3. If one of the suggested codes is right, click it. It is added to the visit as the confirmed primary diagnosis, and the visit leaves the list.
4. If none fits, or there are no suggestions, click **Open the visit to code it** and choose the code on the visit (see [Visits and consultation](05-visits-and-consultation.md)).

Nothing is coded on its own: a person always chooses. If you are a Records Officer and you are not sure, ask the doctor rather than picking the nearest code. When the list is empty you see "Every written diagnosis is coded."

## Suggestions: what is worth a look

True-Doctor watches your records and suggests what needs attention, each with a short reason and a button that takes you to the right page. Each person only sees suggestions about work they are allowed to do. Some examples:

- **Notifiable cases this week**: a notifiable condition was coded in the last seven days. It names the conditions and opens the 033b form, so you can tell the district surveillance focal person.
- Unpaid and overdue invoices, and visits closed without a bill.
- Visits left open, patients waiting a long time in the queue, booked patients who have not arrived, and no-shows to rebook.
- Lab and imaging work still without a result, and lab results that came back after the patient left.
- Stock running out, expiring or expired.
- Dental check-ups that are overdue (see [Dental](10-dental.md)).
- Insurance claims waiting for action, books not yet up to date, and no open financial year.
- Setup not finished, and for your own copy, the updates window ending.

The suggestions appear on the home screen of the phone app in the **Worth a look** card (see [The mobile app](14-mobile-app.md)), and the top three go into the owner's daily summary below. The web dashboard is described in [Getting started](01-getting-started.md).

## The owner's daily summary

The Hospital Admin can receive a short message every morning with the day before in figures. It is off until you turn it on.

The summary says:

- How many patients were seen.
- How much money came in, by payment method, and how much of it paid older bills.
- What was left unpaid that day, and what is owed in total.
- Stock that is out, low or expired, if your practice runs a pharmacy.
- The top three "worth a look" items.
- How many appointments are booked for today.

### Turning it on

1. Open **Messages to patients** under Settings.
2. Find the **My daily summary** card.
3. Tick **Send me a summary of each day**.
4. In **Send it by**, choose **Email** (to your sign-in address) or **Text message** (to the mobile number on your profile).
5. In **At**, choose the time you want it. The default is 07:30.
6. Press **Save**.

The card shows **What it would say today**, so you can read an example before you turn it on. **The last few days** lists each summary with whether it was sent or failed, and why it failed.

A few things to know:

- The summary always covers the day before, by your hospital's own time.
- It is never sent during the quiet hours set on the same page. If you choose a time inside them, it waits until they end.
- You get one summary a day. If sending fails, True-Doctor tries again a little later.
- A text message uses your clinic's message balance, like messages to patients. If there is no balance left, it is not sent and the list says so.
- You cannot turn on text messages without a mobile number on your profile, or email without an email address.

Each Hospital Admin sets their own summary. One person's choice does not affect another's.

## Good habits

- Clear **Diagnoses to code** every week, not at the end of the month.
- Before submitting the 105 or 108, make sure **Missing data** is empty.
- Check the 033b every Monday for the week just ended, and act at once on any notifiable case.
- Use **Last month** on the Reports page for your monthly management meeting, and print it.
