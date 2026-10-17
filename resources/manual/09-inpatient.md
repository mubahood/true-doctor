# Inpatient

This chapter is for doctors, nurses and the Hospital Admin who look after admitted patients. It covers setting up wards and beds, admitting a patient, the occupancy board, the ward round record (vitals rounds, medications and nursing notes), moving a patient to another bed, and discharge.

A stay in hospital is always raised on a visit, in the same way as a lab test or a scan. The bed charge goes onto that visit's bill one night at a time, so the bill is never a surprise at discharge.

## Who can do what

| Role | What they can do |
|---|---|
| Hospital Admin | Everything, including setting up wards and beds and taking a bed out of service |
| Doctor | See the board and admissions, admit, transfer, discharge, record ward round entries |
| Nurse | The same as the Doctor |
| Other roles | Do not see the inpatient menu |

In the sidebar, the **Inpatient** group holds **Occupancy board** and **Admissions**. **Wards** and **Beds** are under **Hospital** in the Administration part of the menu. If your hospital's practice type does not use inpatient care, for example a dental clinic, these entries do not appear.

## Setting up wards and beds

Do this once, before the first admission. Only the Hospital Admin can add or change wards and beds.

### Add a ward

1. Open **Wards** and press **New ward**.
2. Type the **Name**, and a **Description** if you like.
3. Set **Nightly charge per bed**. This is the standard rate for the ward and the starting price for each new bed. Each bed keeps its own charge, so a side room can cost more.
4. Leave **Active** ticked and press **Create ward**.

**Import samples** offers a starter list of common wards. Tick the ones you want and press **Import wards**.

To reprice a whole ward, open it with the pen icon, change **Nightly charge per bed** and tick **Also set every bed in this ward to that amount**. The form tells you how many beds will change. Nights already on a patient's bill keep the price they were billed at; only nights still to be billed use the new rate.

### Add a bed

1. Open **Beds** and press **New bed**.
2. Choose the **Ward** and type the bed's **Name**, for example "Bed 4".
3. Set the **Nightly charge**.
4. Set the **Status** to **Available**.
5. Leave **Active** ticked and press **Create bed**.

A bed with a patient in it cannot be deleted, and its status cannot be changed from this form. True-Doctor changes it for you when a patient is admitted, moved or discharged.

## The occupancy board

**Occupancy board** shows every bed in every active ward. It refreshes by itself every 30 seconds while it is open.

At the top you see the total **Beds**, how many are **Occupied**, **Free now** and **Out of service**, and a bar showing how full the hospital is. These figures always count the whole hospital, even when you filter the board.

Each ward is a card of bed tiles:

- An occupied bed shows the patient's name, how many nights they have stayed and the date they came in.
- A free bed shows its status and its nightly price.

Use the search box to find a bed or a patient by name, **Every ward** to show one ward, and **Any bed** to show only available, occupied or out of service beds.

Press a tile to open the bed over the board. For an occupied bed you see the patient, when they were admitted, the doctor they are under, the visit, and **Where they have been moved**. For an empty bed you see its ward, whether it can take a patient, and the **Last patient in it**. The buttons at the bottom let you act on the bed straight away:

- **Admit into it** on a free bed.
- **Transfer** and **Discharge** on an occupied bed.
- **The admission** opens the full stay.
- **Out of service** takes an empty bed out of use, for example for repair or cleaning. Nobody can be admitted to it until you press **Back in service**. Only the Hospital Admin sees these two buttons.

## Admitting a patient

The patient must have an open visit. If they have only just arrived, open a visit for them first. See [Visits and consultation](05-visits-and-consultation.md).

You can admit from three places: **Admit into it** on a free bed on the board, the **Admit** button on **Admissions**, or by placing an Admission order on the visit itself (explained in chapter 05). The steps are the same:

1. In **Visit**, search by the patient's name or number, or the visit number, and pick the visit. True-Doctor confirms who you are admitting and on which visit.
2. In **Bed**, pick a bed. Only available, active beds are listed. If you started from a bed on the board, it is already chosen.
3. In **Admitting doctor**, pick the doctor taking the patient on.
4. **What the stay is called** is how the stay appears in the visit's list of work. It defaults to "Inpatient stay". You can pick a suggestion such as **Post-operative stay**, **Observation**, **Maternity stay** or **Day case**.
5. Type the **Reason for admission**.
6. Press **Admit patient**.

The bed becomes occupied at once. Two people cannot put patients into the same bed, even at the same moment.

## How the stay is billed

Just after midnight each night, True-Doctor adds the night just finished to the visit's bill, at what the bed costs that night. Anything not yet billed is added at discharge. A stay is always charged for at least one night. The total bed charge appears on the admission once it is closed.

## The Admissions list

**Admissions** lists stays, showing current patients by default. Use the status filter to see **Discharged**, **Deceased** or **Absconded** stays instead, and the search box to find a patient. Press **Occupancy** to go to the board.

Press a patient's name for a quick view. The row's actions menu has **Full record**, which opens the admission page, and **The patient**, which opens their record.

## The admission page

The left side shows the stay: patient, ward and bed, when admitted, the doctor, status, nights so far, the reason and the visit. Below it are **Bed transfers**, and after discharge the **Discharge notes**.

The right side holds the ward round record. Entries can be added only while the patient is still admitted, and they cannot be edited afterwards. Each entry is stamped with the time and your name.

### Vitals rounds

1. In **Vitals rounds**, fill in what you measured: **Temperature (°C)**, **Blood pressure** (for example 120/80), **Pulse (bpm)**, **Respiratory rate** and **SpO₂ (%)**.
2. Add a **Note** if needed.
3. Press **Record vitals**.

The readings build into a chart below the form. These rounds are separate from the vitals taken at the start of the visit.

### Medication record

1. In **Medication record**, type the **Drug**, the **Dose** (for example 1 g) and the **Route** (for example IV or oral).
2. Choose the **Outcome**: **Given**, **Withheld** or **Refused**.
3. Add a **Note**, for example why a dose was withheld.
4. Press **Record medication**.

Recording a dose here does not take stock off the pharmacy shelf. Drugs are issued through the pharmacy; see [Pharmacy and stock](08-pharmacy-and-stock.md).

### Nursing notes

Type your observation in **New note** and press **Add note**.

## Moving a patient to another bed

1. On the admission page press **Transfer**, or open the patient's bed on the board and press **Transfer**.
2. In **To bed**, pick the new bed. Only available, active beds are listed.
3. Type a **Reason** if you like.
4. Press **Transfer**.

The old bed is freed and the new one is occupied in one step. The move is added to **Bed transfers**. A transfer does not end the stay.

## Discharge

1. On the admission page press **Discharge**, or open the bed on the board and press **Discharge**.
2. The dialog, **Close admission**, shows how many nights the stay has run and what will be billed to the visit.
3. Choose the **Outcome**: **Discharged**, **Deceased** or **Absconded**.
4. Type the **Discharge notes**.
5. Press **Close admission** and confirm.

The bed is freed, any nights not yet billed go onto the visit, and the stay is closed. This cannot be undone.

After discharge, the admission page has a **Summary** button that opens the discharge summary as a PDF, with the patient, the length of stay, the bed charge and the notes. **Send to patient** creates a link the patient can open on their phone; see [Patients](03-patients.md).

To take payment for the stay, see [Billing and payments](07-billing-and-payments.md).
