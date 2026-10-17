# Security and privacy

Patients trust you with their health details. True-Doctor helps you keep that trust: it records every change to clinical and money records, records who opened each patient's record, and gives you the documents and tools the data protection law expects a clinic to have.

Most of this chapter is for the Hospital Admin. The pages it describes are under **Administration**: **Audit log** in the **Hospital** group, and **Data protection** and **Data requests** in the **Settings** group.

## Keeping your own account safe

Every staff member can protect their own account from the account menu at the top right, under **Account security**:

- **Two-step sign-in** adds a 6-digit code from your phone after your password, so a stolen password alone cannot open your account. It is optional, and recommended for owners and administrators. How to set it up is in [Getting started](01-getting-started.md).
- **Trusted browsers** lists browsers that skip the code. **Ask every browser again** makes every browser ask for the code again.
- **Apps signed in** lists phones signed in to your account. You can sign out any one of them.
- **Sign out everywhere else** signs out every other browser and app at once. Use it if you lose a phone or signed in on a computer you do not trust. You stay signed in where you are.

If a staff member's phone is lost, also block it under **Offline devices** so its copy of the records is cleared. See [The mobile app](14-mobile-app.md).

Good habits matter as much as settings: never share a password, sign out of shared computers, and set a PIN on any phone that holds patient records.

## The audit log

The **Audit log** shows every change to a clinical or money record, with what each field was before and after, and every time somebody opened or printed a patient's record. Nothing in it can be edited or removed. Confidential fields show only that they changed, never what they say.

The page has two tabs.

### Changes

This tab lists changes to records such as patients, visits, diagnoses, orders, lab and radiology results, prescriptions, dispensing, admissions, nursing notes, invoices, payments, cards, insurance claims, stock, the price list and user accounts. A change of a staff member's role shows here too.

Each row shows **When**, the **Record**, **What happened**, the **Fields** that changed, **Who** made the change, and **Through** (Web, App or System).

To find a change:

1. Type a record number in the search box, or choose a record type and a kind of change from the lists.
2. To narrow it further, pick a person in **Anyone** and set the dates.
3. Click a record to see each changed field with its value **Before** and **After**, and where the change came from.

Click **Clear** to remove all filters.

### Records opened

This tab lists every time a patient's record was opened or printed: **When**, **Patient**, **What** (opened or printed, and what was printed), **Who**, and **On** (Web or App, with the device). Filter by patient, by opened or printed, by person and by dates.

Opening the same record again within a few minutes counts once, so the list stays readable.

### Who opened one patient's record

On a patient's record, the **Who opened it** tab shows everyone who opened or printed that patient's record, with their role, the date and time, and whether it was on the web or the app. Only the Hospital Admin sees this tab. Click **Audit log** on the tab to open the full log for that patient.

Use it when a patient asks who has seen their file, or when you suspect someone looked at a record without a reason.

## Confidential notes

A visit can carry a **Confidential note** for sensitive matters such as HIV status, mental health or sexual and reproductive health. Only staff who can diagnose can read it. Others see only that a confidential note is on the visit. Every time it is read, the reading is recorded in the audit log. It is not printed on the visit report and is never sent to phones or offline devices. Writing clinical notes is covered in [Visits and consultation](05-visits-and-consultation.md).

## Data protection documents

The data protection law asks a clinic to have certain documents: a notice for patients, a way to answer their requests, a retention schedule, a breach procedure, an impact assessment and registration with the Personal Data Protection Office. Your clinic decides what is done with patients' data, so these documents are filled in with your clinic's details for you.

Open **Settings**, **Data protection**. Under **Your documents**, each document has an **Open PDF** button:

| Document | What it is for |
|---|---|
| Data processing agreement | Between your clinic and the True-Doctor company, for the online service. Only shown on the online service. |
| Privacy notice for patients | Print it for the waiting room and give a copy to any patient who asks. |
| Patients' rights procedure | How to handle a request to see, correct or object to the use of personal data. |
| Retention schedule | How long each kind of record is kept, and why. |
| Data breach procedure | What to do in the first hours after data is lost, stolen or seen by the wrong person. |
| Data protection impact assessment | The risks to patients' data in this system and how each is controlled. |
| PDPO registration checklist | What you need to register with the Personal Data Protection Office. |

Have the documents read by your own legal adviser before you rely on them.

### Accepting the data processing agreement

On the online service, the top of the page shows whether your clinic has accepted the current data processing agreement. If it has not, or a new version has come out:

1. Open the **Data processing agreement** PDF and read it.
2. Click **Accept version** followed by the version number.

The page then shows who accepted it and when.

## Patients' data requests

A patient, or their parent or guardian, may ask to see their data, correct it, stop a use of it, or delete it. The law expects an answer without delay. In True-Doctor each request is due 30 days after you receive it.

Open **Settings**, **Data requests**. You can also reach it with the **Data requests** button on the **Data protection** page, which shows how many are open.

### Logging a request

1. Click **Log a request**.
2. Choose the **Patient**, if the request is about a registered patient.
3. Fill in **Who asked** and **Their phone or email**.
4. In **They asked to**, choose one: **See their data**, **Correct their data**, **Object to a use** or **Delete their data**.
5. Set **Received on** and describe **What they asked**.
6. Click **Log request**.

The list shows each request with **Who asked**, **Patient**, **Asked to**, **Received**, **Due** and **Status**. A late request is marked **Overdue**. Filter by status (**Open**, **Answered**, **Refused**) or by kind.

### Answering a request

Click a request to open it. Under **How to answer**, the page tells you what to do for that kind:

- **See their data:** give them a copy. Click **Their data (PDF)** to print it, or **As a data file** for a computer-readable copy.
- **Correct their data:** click **Open their record** and correct it the normal way. The change is logged.
- **Object to a use:** this is usually about messages. Switch off their consent for messages on their record (see [Patients](03-patients.md)).
- **Delete their data:** medical records must be kept for the retention period. Explain this to the patient, and record what was removed, if anything.

Then:

1. In **What was done**, write what you did. For a refusal, write the reason the patient is given.
2. Click **Mark as answered**, or **Refuse, with the reason**.

The request then shows who closed it and when. It stays in the list as a record.

## Exporting all your data

You can take a full copy of everything your hospital holds in True-Doctor at any time. You might do this to keep your own archive, or before moving to another system.

1. Open **Settings**, **Data protection**.
2. Under **Export all your data**, click **Prepare an export**.
3. The export is prepared in the background. The list shows its status. You can leave the page and come back.
4. When it is ready, click **Download**.

The file holds every record your hospital holds, one spreadsheet (CSV) per table with a list of what each column means, and every uploaded document and photo. The download link works for 48 hours, only for you and the hospital's administrators. Every download is logged, and the list shows how many times each export was downloaded.

Keep the downloaded file as carefully as the system itself: it contains every patient's details.

## If data is lost or seen by the wrong person

Open the **Data breach procedure** from **Data protection** and follow it from the first hour. Use the **Audit log** to find out what was opened or changed, by whom and when, and block any lost device under **Offline devices**.
