# Patients

Every person you treat has one patient record in True-Doctor. The record holds who they are, how to reach them, their allergies, every visit, every bill and payment, and the files you keep for them. This chapter shows you how to register a patient, find one again, read the record, record their consent to messages, print their ID card and statement, and send them documents by link.

## Who can do what

| Role | What they can do with patients |
|---|---|
| Hospital Admin | Everything, including archiving a patient and seeing who opened a record |
| Receptionist | Register, edit, issue and top up cards |
| Records Officer | Register, edit and archive |
| Doctor, Nurse | Look up and edit |
| Pharmacist, Lab Technician, Radiologist, Accountant | Look up |

What you see inside a record also depends on your role. For example, only staff who can see billing see the money figures and the **Billing** tab. A part you are not allowed to see is left out, not shown empty.

## Register a new patient

1. Open **Patients** in the sidebar.
2. Press **Register patient**. A form opens over the list.
3. Fill in **First name** and **Last name**. These are the only required fields.
4. Add what you can of the rest. The form has five parts:
   - **Identity**: **Date of birth**, **Sex**, **Blood type**, **Status**.
   - **Contact**: **Phone (primary)**, **Phone (alt)**, **Email**, **District**, **Current address**, **Home address**.
   - **Medical**: **Allergies (comma-separated)** and **Chronic conditions (comma-separated)**. Type each one separated by a comma, for example "Penicillin, Peanuts".
   - **Family & emergency**: **Spouse name**, **Father name**, **Mother name**, **Emergency contact**, **Emergency phone**.
   - **Insurance & consent**: **Insurance provider**, **Insurance member no.**, **Notes**, and the consent boxes (see below).
5. Press **Register patient**.

True-Doctor gives the patient a number, for example PT-2026-000123K. The number is unique in your hospital and never changes. The last letter is a check character, so a mistyped number is less likely to find the wrong person.

Always ask about allergies, even if the answer is "none". Clinical screens warn staff when allergies have not been recorded.

You can also register a patient while opening their first visit. See [Visits and consultation](05-visits-and-consultation.md).

## Record consent

The **Insurance & consent** part of the form has two kinds of consent.

**Patient consents to data processing** records that the patient agreed to the hospital keeping their records. If it is not ticked, the patient record shows **No consent recorded** at the top.

**Text messages** records whether the patient agrees to receive messages from the hospital. Read the sentence on the form to the patient, then tick each box they agree to:

- **About their care, by SMS**
- **About their care, by WhatsApp**
- **News and offers**

Care messages are things like appointment reminders, receipts and result links. News and offers is separate, and needs its own yes. True-Doctor only texts a patient about their care if they said yes to it. A patient can also stop messages at any time by replying STOP.

Each answer is kept with the date and the name of the staff member who recorded it. To change an answer later, edit the patient and tick or untick the box. You can see the full list of answers on the **Details** tab under **Text messages**.

How messages are switched on for the whole hospital is in [Setting up your hospital](02-setting-up.md).

## Find a patient

1. Open **Patients**.
2. Type in the search box (**Search name, number or phone**). The list narrows as you type.

You can search by first name, last name, patient number, or phone number. A phone number is found however it was written: 0772 123456, +256 772 123456 and 772123456 all find the same patient. You can type several words, for example a first name and a last name.

Use **All statuses** to show only **Active**, **Inactive** or **Deceased** patients. Click a column heading such as **Patient no.**, **Name** or **Status** to sort by it.

### Quick view

Click a patient's number or name to open a quick view over the list. It shows what the desk needs before booking or treating:

- the number of visits, when they were last seen, and how much they owe the hospital
- allergies, long-term conditions and blood type
- how to reach them, and who to call in an emergency
- their recent visits

From the quick view you can press **Full record**, **Edit details** or **ID card**.

The actions menu at the end of each row also offers **Quick view**, **Full record** and **ID card**.

## The patient record

Open a patient's **Full record**. The page has four parts.

**The header** shows the name, patient number, age, sex and status, with these buttons:

- **New visit** opens the new-visit form with this patient already chosen. If the patient already has an open visit, the button reads **Open visit** and its number instead, and takes you to it.
- **Book appointment** opens the booking form with this patient chosen.
- **Statement** prints the statement of account (see below).
- **ID card (PDF)** prints the ID card.
- **Edit** opens the patient form.
- **Archive** removes the patient from the active list. Only the Hospital Admin and the Records Officer see it. Nothing is deleted: the record is kept.

**The alert strip** shows only what applies to this patient: allergies, chronic conditions, money owed and how old the oldest unpaid bill is, no consent recorded, inactive or deceased status, currently admitted (ward and bed), and insurance on file with any claims waiting.

**The figures row** shows **Outstanding**, **Billed**, **Paid**, **Card balance**, **Visits** and **Next appointment**, depending on your role.

**The tabs** hold the rest.

### The tabs

| Tab | What it holds |
|---|---|
| **Overview** | Recent activity on the record, newest first, and the latest visits, invoices, payments, medication, diagnoses, lab orders, imaging, admissions and appointments |
| **Visits** | Every visit with its date, stage, doctor and diagnosis, and the amount billed and still owed |
| **Billing** | Invoices (with PDF), payments (with receipt), **Debts** grouped by age (0 to 30, 31 to 60, 61 to 90 and over 90 days), insurance claims awaiting payment, and the card ledger |
| **Clinical** | Diagnosis history, current medication, prescriptions, vitals from past visits, lab results with abnormal flags, imaging reports, and admissions |
| **Appointments** | Upcoming and past appointments |
| **Records** | Cards, documents, dependents, insurance coverage and treatment records |
| **Details** | Everything held on the patient: identity, contact, medical, family, insurance, consent and notes |
| **Who opened it** | Every time someone opened or printed this record. Only the Hospital Admin sees this tab. See [Security and privacy](15-security-and-privacy.md) |

Some tabs appear only where they apply. A dental practice shows a **Dental** tab (see [Dental](10-dental.md)). **Pregnancy**, **Immunisation** and **Growth** appear for the patients and practices they suit (see [Mother and child](11-mother-and-child.md)).

The tab you are on is kept in the page address, so you can bookmark it or send the link to a colleague who has the same access.

## The Records tab

### Documents

Keep scanned papers here: ID copies, insurance cards, consent forms, referral letters, outside results.

1. On the **Records** tab, find **Documents** and press **Upload**.
2. Choose the **Type**: id, insurance, consent, referral, result or other.
3. Choose the **File**. PDF, image or Word document, up to 10 MB.
4. Add a **Note** if it helps, then press **Upload**.

Uploading and removing documents, and linking dependents, need a role that can edit patients (Hospital Admin, Receptionist, Records Officer, Doctor or Nurse).

Click a file name to open it. Files are stored privately and only open for staff who can see the patient. To remove one, press the bin icon on its row.

### Dependents and guardians

Link a child, spouse or other dependent to the patient who is responsible for them.

1. Under **Dependents & guardians**, press **Link dependent**.
2. In **Find the dependent**, type at least two characters of their name, number or phone, then press **Select** next to the right person. The dependent must already be registered.
3. Choose the **Relationship**: child, spouse, ward, parent, sibling or other.
4. Press **Link dependent**.

On the dependent's own record, the same panel shows **This patient is a dependent of** with the guardian's name. To remove a link, press the unlink icon on its row.

### Insurance coverage

Staff with the right role (Hospital Admin or Accountant) can record which insurer covers the patient.

1. Under **Insurance coverage**, press **Add coverage**.
2. Pick the **Provider**, enter the **Member no.** and the **Coverage %**.
3. Add **Valid from** and **Valid to** if the cover has dates. Leave **Active** ticked.
4. Press **Add coverage**.

Expired cover is marked **Expired**. Claims are covered in [Billing and payments](07-billing-and-payments.md).

### Cards

Where your hospital uses prepaid cards, the **Cards** panel lets the Receptionist, Accountant or Hospital Admin press **Issue card**, top a card up or charge it. The whole subject, including family cards and insurer cards, is in [Billing and payments](07-billing-and-payments.md).

### Treatment records

Doctors and nurses can keep a record of a procedure, such as a dressing or minor surgery, with photos.

1. Under **Treatment records**, press **Add record**.
2. Fill in **Procedure**, and **Performed at** if it was not just now.
3. Add **Notes**, and up to 12 **Photos** of up to 8 MB each.
4. Press **Add record**.

Click the procedure name to open the record and its photos.

## Print the ID card

Press **ID card (PDF)** on the record, or **ID card** in the patient list menu or quick view. A small card opens in a new tab with the hospital's name and logo, the patient's details, and a QR code of the patient number. Print it on card or paper and cut it out. Staff can scan or read the number at the next visit to find the patient quickly.

## Print a statement of account

Press **Statement** on the patient record. You need a role that can see billing (Hospital Admin, Doctor, Receptionist or Accountant).

The statement opens as a PDF on your hospital's letterhead. It lists every invoice and every payment, the total billed, the total paid, the **Balance due**, and how old the balance is. Give it to a patient who asks what they owe, or to a company that pays for them. Each time a statement is printed, True-Doctor records who printed it.

## Send a document to a patient by link

Many documents can be sent to the patient's phone as a private link: a lab result, a radiology report, a discharge summary, a visit's prescription, or a dental quote. Look for the **Send to patient** button on that document's page. A document can only be sent once it is ready, for example a lab result after it is completed.

1. Press **Send to patient**.
2. Choose how long the link should work in **Link works for**: 1, 3, 7, 14 or 30 days.
3. Tick **Text the link and code to the patient** if you want True-Doctor to text it. This only works if the patient agreed to care messages and your hospital has messages switched on.
4. Press **Make the link**.
5. The link and a six-digit **Code** appear. The code is shown only once. Press **Copy** to copy the link if you want to send it yourself.

The patient opens the link and types the code, or their date of birth, to see the document. After 5 wrong tries the link locks for good and you must send a new one.

### The Patient links page

Open **Patient links** in the sidebar to see every link the hospital has sent. Each row shows the document, the patient, who sent it, when it stops working, how many times it was opened, and its state: **Active**, **Expired**, **Withdrawn** or **Locked**.

- Use the search box (**Patient or number**) and the **All links** filter to narrow the list.
- Click a document name, or **Who opened it** in the row menu, to see every attempt to open the link: when, whether the code was right or wrong, and from which device.
- To stop a link at once, choose **Withdraw** from the row menu and confirm. Use this if a link was sent to the wrong number.

## Edit or archive a patient

To correct details, press **Edit** on the record (or the pencil icon in the list), change what you need, and press **Save changes**. The patient number cannot be changed.

To mark a patient as no longer attending, set **Status** to **Inactive**. If a patient has died, set it to **Deceased**: clinical screens then warn staff before they act on the record.

**Archive** is for a record created by mistake, such as a duplicate. It needs the Hospital Admin or Records Officer role. The record is kept, but it leaves the patient list.
