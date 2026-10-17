# Visits and consultation

A visit is one attendance by a patient, from the moment they arrive until their bill is settled. Everything that happens to the patient on that day belongs to the visit: vitals, the doctor's notes, diagnoses, lab tests, scans, drugs, procedures, an admission, and the bill. This chapter shows you how to open a visit, record the clinical work, place orders and prescriptions, and close the visit.

Other chapters pick up from here. Lab and radiology staff work on the orders you place, as described in [Lab and radiology](06-lab-and-radiology.md). The cashier takes payment, as described in [Billing and payments](07-billing-and-payments.md). The pharmacy dispenses, as described in [Pharmacy and stock](08-pharmacy-and-stock.md).

## Who can do what

| Role | What they can do on a visit |
|---|---|
| Hospital Admin | Everything, including setting a visit's state out of order |
| Receptionist | Open visits, add orders, move the visit to billing and payment, cancel a visit |
| Doctor | Write clinical notes and coded diagnoses, add orders, order lab tests and imaging, prescribe, move the visit on, cancel a visit |
| Nurse | Record vitals, give and record prescribed doses |
| Pharmacist, Lab Technician, Radiologist | See visits, and work on the orders sent to them |

## Open a visit

Usually the receptionist opens the visit when the patient arrives.

1. Open **Visits** in the sidebar and press **New visit**. You can also press **New visit** on the patient's record, which chooses the patient for you.
2. Under **Who is it?**, choose **Registered patient** and search by name, number or phone. For someone who has never been here, choose **New patient** and fill in **First name**, **Last name**, **Sex**, **Date of birth** and **Phone**, and tick **Consent given** and **Texts about their care (SMS and WhatsApp)** if the patient agrees.
3. Read the box that appears under a registered patient. It may say:
   - **Already has an open visit**. Opening a second one splits their bill in two. Press **Go to it** to use the open one instead.
   - **Booked today at** a time. Press **This visit fulfils it** to link the visit to that appointment.
   - **Outstanding balance** from earlier visits. Raise it with the patient while they are at the desk.
4. Under **How are they?**, type any vitals you have taken: **Temp °C**, **BP mmHg**, **Pulse bpm**, **Resp /min**, **SpO₂ %**, **Weight kg**, **Height cm**. **BMI** is worked out for you. Leave a reading empty if it was not taken.
5. Under **Why have they come?**, write the **Reason for visit** and the **Presenting complaints**. Click a suggested phrase to add it; click again to take it out. Add a **Diagnosis** only if it is already known.
6. Under **Is anybody seeing them yet?**, choose **Pending** (registered, waiting) or **Ongoing** (being seen now).
7. Press **Create visit**, or **Register & create visit** for a new patient.

True-Doctor gives the visit a number and confirms it at the top of the screen.

A visit can also start from an appointment. See [Appointments and the queue](04-appointments-and-queue.md).

## The visits list

**Visits** lists every visit with its number, patient, doctor, status, stage, number of orders, total, balance, and when it was opened. Search by patient or visit number, and filter by status and stage.

Each row's actions menu offers:

- **Open visit**: opens the visit over the list.
- **Visit report (PDF)**: the whole visit as a document the patient can carry to another hospital.
- **Add an order**: opens the visit with the order form ready.
- **Ready for billing** or **Ready for payment**: only when the visit can move on (see below).
- **Cancel visit**.
- **Bill & payments**: jumps to the bill.
- **Full page**: opens the visit as its own page.
- **Set state…**: Hospital Admin only.

## Working inside a visit

Click a visit number to open it. The visit opens over the list in a large window, with a rail of sections on the left: **Summary**, **Orders**, **Bill**, **Payments** and **History**. Click a section to jump to it. Press **Full page** to open the same visit as a page of its own. **Add order** sits at the top of the window, so it is always one click away.

The full page shows the same visit laid out in panels: **Vitals**, **Clinical**, **Coded diagnoses**, **Bill**, **Lab orders**, **Radiology**, **Dispensing**, **Prescriptions** and **History**. Some panels appear only for the roles that use them. If your hospital has a receipt printer set up, the full page also has a **Queue ticket** button that prints the patient a slip with their number for the day, to wait until called.

At the top of every visit, True-Doctor shows the patient's allergies and long-term conditions. If allergies were never recorded, it says **Allergies not recorded**. Ask the patient, then record the answer on their record, even if it is "none". If the patient is recorded as deceased, a warning says so.

## Record vitals

Nurses, doctors and the Hospital Admin can record vitals.

1. In the **Vitals** panel, press **Record vitals** (or **Edit vitals** if some are already in).
2. Fill in what you measured: **Temp °C**, **Blood pressure**, **Weight kg**, **Height cm**, **Pulse**, **SpO₂ %**, **Resp. rate**. Under some fields you can click a value, including the patient's last reading.
3. Check the **BMI**, which is worked out as you type.
4. Press **Save vitals**.

Vitals from past visits are on the **Clinical** tab of the patient record, newest first, so you can read the trend.

## Write clinical notes

Doctors and the Hospital Admin write the notes.

1. In the **Clinical** panel, press **Write notes** (or **Edit notes**).
2. At the top of the form you see what is already known: allergies, ongoing conditions, today's vitals, and the diagnosis from the last visit.
3. Pick the **Doctor** who saw the patient.
4. Fill in **Complaints**, **Diagnosis** and **Doctor's remarks** (the plan, and anything the next person needs). Click a suggested phrase to add it to the text; you can still edit the text afterwards. Phrases marked as coming from reception are what the desk wrote when opening the visit.
5. Use **Confidential note** for sensitive matters such as HIV status, mental health, or sexual and reproductive health. It is stored encrypted and shown only to clinicians who can diagnose. Other staff see only that a confidential note exists.
6. Press **Save notes**.

## Code the diagnosis

The written diagnosis stays in your own words. The coded diagnosis is an ICD-10 code, and it is what the Ministry of Health reports count. Code each visit so your reports are complete. The **Coded diagnoses** panel is on the visit's full page.

1. Open the visit's **Full page** and find **Coded diagnoses**.
2. If you have written a diagnosis, True-Doctor suggests codes under **From the written diagnosis:**. Click one to add it.
3. Otherwise, type in the box (**Type the diagnosis in plain words or a code**). You can type ordinary words, including in Luganda or Swahili, or the code itself. Your practice's most used codes come first. Click the right one to add it.
4. If the diagnosis is not yet certain, tick **Suspected** before you click the code.

The first code you add is marked **Primary**. To change which one is primary, press **Make primary** on another. Use **Mark suspected** or **Mark confirmed** to change certainty, and the cross to remove a code. True-Doctor keeps every change, with who made it.

Visits that were not coded are collected on the **Diagnoses to code** page, where a Records Officer can code them later. See [Reports and ministry forms](12-reports-and-ministry-forms.md).

## Place orders

An order is one piece of work for the patient: a consultation, a lab test, a scan, drugs from the pharmacy, a procedure, or an admission. Every order carries its cost, so the bill builds up as you order. You do not need to bill anything twice.

### Add an order

1. Press **Add order** (in the visit window, or in the **Orders** section).
2. Under **What kind of work?**, choose **Consultation**, **Lab test**, **Imaging**, **Pharmacy**, **Procedure** or **Admission**.
3. For **Lab test** or **Imaging**, tick the tests or studies under **Which tests?** or **Which studies?**. Each shows its price. Type to search a long list.
4. For other kinds, describe it in **What is it?**, for example "Wound dressing". Click a suggestion if one fits.
5. Choose who should do it in **Assign to**, and the **Department**, if you know them.
6. Add **Instructions** (or **Clinical notes** for lab and imaging) for the person doing the work.
7. Press **Place order**.

When it is placed, the window says what was done and asks **Anything else for this patient while you are here?** Press **Add another** to place the next order, or **Done for now** to close.

An **Admission** order also asks for the **Ward** and a free **Bed**, and the **Admitting doctor**. Press **Admit patient** to take the bed. The rest of inpatient care is in [Inpatient care](09-inpatient.md).

On the full page, doctors can also use **Order tests** in the **Lab orders** panel and **Order imaging** in the **Radiology** panel. Tick the tests or studies and press **Order & bill**.

### Follow an order

The **Orders** section lists every order with what it is, who it is assigned to, when it was raised, its status and its cost. Use the chips above the list (**All**, **Consultation**, **Lab test** and so on) to show one kind.

An order's status is **Pending**, **In progress**, **Completed** or **Cancelled**. Click an order to open it. The person whose work it is can:

- add what it used: choose **Service** (from the price list) or **Product** (off the pharmacy shelf), pick the item, set **Qty**, add a **Note** if needed, and press **Add**. A product comes off the shelf at the same moment it goes on the bill.
- write the **Report**: what was found. It saves as you type.
- attach files such as results, films or consent forms, up to 10 MB each. Press **Choose files** or drop them on the box.
- press **Mark in progress** or **Mark completed**.

An order cannot be completed with nothing on it. It needs at least a charge, a written report, or an attached file. If none is there, a note in place of the button says what is missing.

To call an order off, press **Cancel order**. True-Doctor shows what will be undone: the charges that come off the bill and any products that go back on the shelf. Give a reason under **Why is it being cancelled?** and confirm, or press **Keep the order**.

Once the visit's invoice has been raised, the items on its orders can no longer be changed.

How the lab and radiology work their orders, and how results come back, is in [Lab and radiology](06-lab-and-radiology.md).

## Write a prescription

Doctors and the Hospital Admin prescribe from the **Prescriptions** panel on the visit's full page.

1. Press **Write prescription**.
2. For each drug, fill in **Drug** and **Dosage** (for example 500mg).
3. Tick the **Daily slots** when it is taken: **Morning**, **Afternoon**, **Evening**, **Night**.
4. Set **Days**, or click 3 days, 5 days, 1 week, 2 weeks or 1 month.
5. Set **Start** if it does not start today, and add **Instructions** such as "after meals".
6. Press **Add another drug** for the next one. Remove a drug with its bin icon.
7. Add any **Notes** and press **Add prescription**.

True-Doctor builds a dose schedule from the slots and days. Each dose shows its date, slot and status. Nurses press **Given** when a dose is given, or **Missed** if it was not.

To print the prescription, press **Prescription** at the foot of the visit window. You can also send it to the patient's phone with **Send to patient** (see [Patients](03-patients.md)). Giving out the drugs is done by the pharmacy, as described in [Pharmacy and stock](08-pharmacy-and-stock.md).

## Move the visit on

A visit has a **status** and a **stage**.

- **Status** says whether the visit is alive: **Pending** (opened, nothing done yet), **Ongoing** (work is happening) or **Completed**. A pending visit becomes ongoing by itself when the first work is recorded.
- **Stage** says what is being done now: **Ongoing** (care is being given), **Billing** (care is finished, the bill is being drawn up), **Payment** (the bill is issued, money is being collected) and **Completed** (settled).

You do not choose the stage from a list. Each step opens only when the work behind it is done:

| From | To | What must be true | How |
|---|---|---|---|
| Ongoing | Billing | No order is still open | Press **Ready for billing** |
| Billing | Payment | An invoice has been raised | Press **Ready for payment** |
| Payment | Completed | The balance is zero | Happens by itself when the last payment is taken |

Until a step can open, the visit says why in plain figures, for example "2 orders are still open" or the amount still outstanding. A patient still in a hospital bed has an open admission order, so the visit cannot reach billing until they are discharged.

Raising the invoice and taking payment are covered in [Billing and payments](07-billing-and-payments.md). The **Bill** and **Payments** sections of the visit show the running total, the invoice and what has been paid.

## Cancel a visit

Use this when a visit was opened in error, is a duplicate, or the patient left.

1. Press **Cancel visit**.
2. Write why under **Why is it being called off?**. A reason is required.
3. Press **Cancel visit** to confirm, or **Keep the visit** to go back.

Everything already recorded stays on the visit. Open orders keep their charges until each one is cancelled.

## Set a visit's state out of order

Sometimes a visit is finished by mistake or cancelled at the wrong desk. The Hospital Admin can press **Set state…**, choose the **Status**, **Outcome** and **Stage** directly, give a reason, and press **Set it anyway**. This skips every check, so use it only to correct mistakes. It is recorded in the visit's history as a change out of order, with your name.

## History and the visit report

The **History** section lists every change of the visit's state: when, from what to what, the note, and who did it.

**Visit report** prints the whole visit as one PDF: who the patient is and their allergies, why they came, vitals, what was found, every order and its report, lab and radiology results, prescriptions and what was dispensed, any stay in hospital, and the account. Give it to a patient who is referred elsewhere.
