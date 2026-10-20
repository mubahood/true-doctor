# Dental

This chapter is for dentists, dental assistants and the front desk of a clinic that does dentistry. True-Doctor keeps a chart of every tooth, a treatment plan priced from your own price list, and the date of each patient's next check-up. A procedure marked done is billed on the visit at the same moment, so nothing is forgotten and nothing is billed twice.

Dental work is switched on by your practice type. A dental clinic has it from the start. A hospital or medical clinic gets it when dentistry is one of the specialties chosen under **Your practice** (see [Setting up](02-setting-up.md)). If dentistry is not part of your practice, you will not see any of the screens below.

## Where you find the dental chart

The chart appears in two places, and it is the same chart in both.

- **On the patient record:** open the patient and choose the **Dental** tab. It sits next to the clinical history.
- **On a visit:** open the visit. In a dental clinic, and on a visit in a dental or orthodontic department, the chart is part of the dentist's notes: below **Dental notes**, or under **Examination** in the detailed case sheet (see below). In a hospital that also does dentistry, any other visit shows the chart by itself for a patient whose teeth are already charted; otherwise you see a **Dental chart** card with an **Open the chart** button; press it when you need it.

Work on the visit page when the patient is in the chair. Findings recorded there are linked to that visit, and finished procedures are billed on it.

The **Dental** tab shows on the patient record when your clinic is a dental clinic, when you have an active department with "dent" in its name, or when the patient already has a chart or a plan.

### Who can do what

| Task | Who |
|---|---|
| See the chart and the plan | Doctor, Nurse, Hospital Admin |
| Record findings and plan procedures | Doctor, Hospital Admin |
| Accept, start, mark done or cancel a procedure | Doctor, Nurse, Hospital Admin |
| Set the next check-up | Doctor, Nurse, Hospital Admin |
| Write the dental notes or the case sheet, diagnose, record an X-ray | Doctor, Hospital Admin |
| Read the dental notes or the case sheet | Doctor, Nurse, Hospital Admin |
| See the financial history | Doctor, Hospital Admin |
| Book the review appointment | Doctor, Hospital Admin |

A dentist signs in with the Doctor role. Other roles do not see the dental chart. The front desk sees that a dental consultation took place, but not the dentist's notes.

## Dental notes

A dental clinic writes each visit as **Dental notes**: plain text, no lists to tap through. This is the usual choice for a dental clinic. A hospital that also does dentistry can choose it too, and a dental clinic can choose the **Detailed case sheet** instead (below), under **Settings**, **Your practice**, **Visit form**.

On the visit page, **Dental notes** shows, in this order:

1. **Presenting complaint**: what the patient came with, in their own words. It is the same complaint as on the new visit form.
2. **History**: **Medical history**, **Family history**, **Dental history** and **Surgical history**.
3. **Oral examination**: **Hard tissues (teeth)**, **Soft tissues (gums)**, **X-ray** and **Diagnosis**.

Below the notes come the dental chart, **Treatment done today**, the treatment plan and **Follow-up** with the review appointment.

1. Press **Write notes** (or **Edit notes** once something is saved).
2. Type in the boxes you need. Leave the others empty.
3. Press **Save notes**.

- The **Medical history**, **Family history** and **Surgical history** carry forward. On a new visit they start with what was saved last time, marked **History from the last visit. Not saved on this visit yet.** Read them with the patient, change what is no longer right, and press **Save notes**.
- The **Diagnosis** is also the visit's diagnosis, so the visits list, coding and the reports show it.
- Every save is kept as a new version, so the record shows what was written and when. If a colleague saved the notes while you had them open, True-Doctor tells you and asks you to open them again.
- The first person who can diagnose and saves notes on a visit with no doctor becomes the visit's doctor.

### The printed dental notes

Press **Visit report** on the notes, or on the visits list. The report prints the **Presenting complaint**, **History** with its four headings, the blood pressure, **Oral examination** with its four headings, the dental chart and the **Treatment done**. A heading with nothing written under it is left out.

A visit written before your clinic changed its choice keeps the way it was written: a visit with a case sheet still shows and prints the case sheet, and a visit with only a reason prints the reason as its presenting complaint.

## The dental case sheet

A clinic that chose the **Detailed case sheet** sees the **Dental consultation** on a dental visit in place of the general clinical notes. It follows the case sheet you would write by hand, top to bottom, in the order you work:

1. **History**: presenting complaint, dental history, medical history, surgical history and financial history.
2. **Examination**: extra-oral, intra-oral soft tissues, investigations, and the dental chart as the hard tissues.
3. **Diagnosis**: per tooth or for the mouth, coded for the Ministry reports.
4. **Treatment done today**: the procedures finished and billed on this visit, with the anaesthetic and materials.
5. **Follow-up**: the treatment plan, the next check-up, the instructions given to the patient and the review appointment.

Every part shows what was written, with **Write** or **Edit** to change it. A form opens in place: tap the words that apply, type only what is not on the list, and press **Save**. Nothing you save is overwritten: each save is kept as a new version, so the record shows what was written and when. If a colleague saved the same part while you had it open, True-Doctor tells you and asks you to open it again, so neither of you loses work.

The first person who can diagnose and writes on a visit with no doctor becomes the visit's doctor.

### History

- **Presenting complaint**: tap what the patient came with (**Toothache**, **Swelling**, **Bleeding gums**, **Broken tooth**, **Loose tooth**, **Sensitivity**, **Check-up**, **Cleaning** and others), then **Where**, **How long** and what makes it worse under **Worse with**. **In the patient's words** starts with what reception wrote. What you save here also becomes the visit's complaints, so the queue and the lists show it.
- **Dental history**: the **Last dental visit**, **Previous treatment**, **Brushing**, what else they use to clean (**Also uses**) and **Habits** such as tobacco, sugary drinks or grinding teeth.
- **Medical history** and **Surgical history** belong to the patient, not to one visit. The next time the patient comes, they show what was recorded last time. Ask the patient, then press **Still correct** if nothing has changed, or **Edit** to change it. The badge **Checked on this visit** shows it was asked today. For a new patient with nothing to record, press **Nothing to report**. When the last record says the patient is pregnant or breastfeeding, there is no **Still correct**: press **Edit** and ask again.
  - The medical history holds the **Allergies** and **Long-term conditions** (the same ones as on the patient record; write "None" when there are none, and leave the box empty to keep them as they are; an allergy added on the patient record while you had the form open is kept), **Current medicines**, and **Before treatment, check**: a bleeding disorder or blood thinners, a heart condition or valve, diabetes, high blood pressure, HIV or hepatitis, bisphosphonates and epilepsy. For a woman of child-bearing age it also asks about **Pregnancy**.
  - Whatever you tick under **Before treatment, check**, and a pregnancy or breastfeeding, shows at the top of every visit of the patient under **Check before treatment**, next to the allergies. HIV or hepatitis shows there as **Infection-control precautions**, so the front desk does not read it.
  - The surgical history holds **Past operations**, **Past extractions** and any **Problem with bleeding or healing**.
- **Financial history** shows what billing already knows, so nobody types it again: what the patient **Owes from before**, how they **Usually pay** and their last payment, their **Insurance**, and the treatment plan they have accepted. Add a **Payment arrangement** in your own words, for example "Pays in three instalments, employer pays half". Only staff who can see money see this part.

### Examination

- **Extra-oral** and **Intra-oral soft tissues**: press **Normal** when you find nothing, one tap. When something is not normal, press **Write**, untick **Normal**, and describe only what you found: face symmetry, swelling, lymph nodes, the jaw joint and mouth opening; or the gums, mucosa, tongue, floor of mouth and palate. Oral hygiene, calculus, plaque and pocket depths are recorded either way.
- **Investigations**: press **X-ray taken** for an X-ray taken in the chair. Choose the kind (**Periapical**, **Bitewing**, **Panoramic (OPG)** or **Occlusal**), type the **Teeth** (for example 35 36; leave it empty for a panoramic), write the **Finding** in a line, and attach the **Image** if you have it. The **Charge** is chosen from your price list for you; choose **Not charged** if it is free. Press **Record**. The X-ray is billed on the visit and filed with it; you do not need a radiology order. Lab tests ordered on the visit show here with their results.
- **Hard tissues**: the dental chart, as described below. Click a tooth to record a finding on it.

### Diagnosis

1. Pick the tooth on the chart above, or type it in **Tooth**. Tick surfaces if they matter.
2. Tap the diagnosis: **Caries, enamel**, **Caries, dentine**, **Root caries**, **Reversible pulpitis**, **Irreversible pulpitis**, **Pulp necrosis**, **Apical periodontitis**, **Periapical abscess**, **Retained root**, **Fractured tooth**, **Impacted tooth** and others. Diagnoses of the whole mouth (**Gingivitis**, **Malocclusion**) are listed after **Mouth:** and need no tooth.
3. Tick **Suspected** first if you are not yet sure.

Each diagnosis gets its ICD-10 code, so it is counted on the Ministry's oral diseases line, and it marks the tooth on the chart: caries shows as caries on that tooth, a retained root as to extract, a fracture as a fracture. The same diagnosis on two teeth is two diagnoses. For anything not on the list, type it in the search box under the list, in plain words or as a code. The cross takes a diagnosis off; the chart keeps what was recorded.

### Treatment done today

When you press **Done & bill** on a procedure in the treatment plan, it appears here with the tooth and the price. Under **Anaesthetic and materials**, record the anaesthetic (for example "Lidocaine 2% with adrenaline, 1 cartridge"), the materials and any notes.

### Follow-up

- The **Treatment plan** and **Next check-up** cards work as described below.
- **Instructions to the patient**: press **Write** and tap what you told the patient: **After an extraction**, **After a root canal**, **After scaling and polishing**, **After a filling**, **A new denture**. The ones that fit today's work are ticked for you. Add anything else under **Other instructions** and press **Save**. Press **Print instructions** to print them for the patient, with what was done today and when to come back. The PDF opens in a new tab.
- **Review appointment**: True-Doctor suggests when to see the patient again from what was done, for example a week after an extraction or two days after a new denture. Press **Book review**, check the **Day**, and tap a free time. The review is booked with the visit's dentist and linked to this visit, so the patient gets the usual appointment reminder.

### The printed case sheet

Press **Case sheet** at the top of the dental consultation, or **Visit report** on the visits list. The report prints the same headings as the screen, like a hand-written case sheet: history, examination with the tooth chart drawn as a grid (C caries, F filled, M missing, X to extract and so on, with the surfaces), the X-ray findings, the diagnoses with their codes, the treatment done today, and the plan and follow-up.

## Reading the chart

The chart shows the mouth as you face the patient: the upper teeth on the top row, the lower teeth below, the patient's right on your left. Teeth are numbered the international (FDI) way: 11 to 18, 21 to 28, 31 to 38 and 41 to 48 for adult teeth.

For children, tick **Child's teeth** at the top of the chart to show the milk teeth (51 to 55, 61 to 65, 71 to 75 and 81 to 85). For a patient under 12 the chart opens on the milk teeth already. Untick it to see the adult teeth.

Each tooth shows its number, small marks for its surfaces, and a short word for its latest finding. The key under the chart explains the main colours: **Caries**, **Filled**, **Missing**, **To extract**, and **Crown or implant**.

## Recording a finding

1. Click the tooth on the chart. A card titled **Tooth** and the number opens below the chart, with any surfaces and notes already recorded.
2. Under **Surfaces**, tick the surfaces the finding is on: **M** (mesial), **O** (occlusal), **D** (distal), **B** (buccal), **L** (lingual or palatal) or **I** (incisal). Tick none if the finding is for the whole tooth.
3. Choose the **Finding**: Sound, Caries, Filled, Missing, To extract, Crown, Bridge, Implant, Root canal, Fracture, Mobility or Note.
4. If you chose **Mobility**, choose the **Mobility grade**, 0 to 3.
5. Add a **Note** if it helps.
6. Press **Record**.

Some findings always concern the whole tooth, whatever surfaces you tick: Sound, Missing, To extract, Crown, Bridge, Implant, Root canal and Mobility. Recording **Sound** clears the tooth.

### Correcting a mistake

Findings are never edited or deleted. If you recorded the wrong thing, record the right finding on the same tooth. The chart always shows the latest finding, and the old one stays in the history. This keeps an honest record of what was seen and when.

## Chart history and the chart on an earlier date

Under the plan, **Chart history** lists every finding recorded for the patient, newest first, with the date and time, the tooth and surfaces, the finding and who recorded it.

To see the mouth as it was on an earlier day, pick a date in the date box at the top of the chart. The chart redraws as it stood at the end of that day, and a line under it says which date you are looking at. You cannot record findings while looking at an earlier date. Press **Today** to return to the current chart.

## The treatment plan

The **Treatment plan** card lists what you intend to do for the patient, with the procedure, the tooth and surfaces, the price and the status. Procedures come from your clinic's own **Price list**, with dental services listed first. If a dental procedure is missing, ask your Hospital Admin to add it to the price list (see [Setting up](02-setting-up.md)).

### Planning a procedure

1. Either click a tooth and press **Plan a procedure on** (the tooth number), or press **Add** on the **Treatment plan** card for a procedure that is not on one tooth, such as scaling.
2. In **Plan a procedure**, choose the **Procedure** from the price list.
3. Check the **Tooth** and **Surfaces**. They are filled in from the tooth and surfaces you picked on the chart. Clear the tooth for a procedure on the whole mouth.
4. If the service has a price range on the price list, a **Price** box appears. Enter the price for this patient within the range. Leave it empty to use the lower price of the range.
5. Add a **Note** if needed and press **Add to plan**.

### Moving a procedure along

Each procedure has a status: **Planned**, **Accepted**, **In progress**, **Done** or **Cancelled**. The buttons on each row move it forward:

- **Accept**: the patient has agreed to the plan.
- **Start**: the work has begun, for example the first sitting of a root canal.
- **Done & bill**: the work is finished.
- The cross button cancels the procedure after you confirm.

You do not have to use every step. A procedure can go straight from Planned to Done. Done and Cancelled are final.

### Done means billed

When you press **Done & bill**, True-Doctor asks you to confirm the amount, then adds the charge to the patient's open visit. On a visit page it uses that visit; on the patient record it uses the patient's newest open visit. Pressing it again never bills a second time.

If the patient has no open visit, you see "Open a visit for this patient first: the procedure is billed on it." Open a visit (see [Visits and consultation](05-visits-and-consultation.md)) and press **Done & bill** again. The charge then goes through the normal billing flow at the cashier (see [Billing and payments](07-billing-and-payments.md)).

The procedure also appears as an order in the visit's **Orders** section. You can press **Edit order** on it to change the **Dentist assigned** or the instructions. Its charge stays the one the plan put there; to correct the quantity or a price within its range, press the pencil beside the line under **What it used**.

### Orders in a dental clinic

In a dental clinic the order form says **Treatment** for the kind of work, **Service** for what it is, and **Dentist assigned** for who does it. The dentist box lists only the dentists and other staff marked as seeing patients. **Admission** is not offered, because a dental clinic has no wards; the same goes for **Lab test** and **Imaging** unless your clinic has turned the laboratory or imaging on in its settings. How to place and change an order is in [Visits and consultation](05-visits-and-consultation.md).

### Giving the patient a quote

Once the plan has something on it, press **Quote** on the **Treatment plan** card. A PDF opens in a new tab listing what is still to do (planned, accepted and in progress) with the total. Print it, or press **Send to patient** to send the patient a secure link to the quote (see [Patients](03-patients.md) for patient links).

## The next check-up (recall)

The **Next check-up** card holds the date the patient should come back.

1. To set the usual six-month recall, press **In 6 months**.
2. To choose another date, pick it in the date box and press **Save**. The date must be in the future.
3. To remove the recall, clear the date and press **Save**.

If your clinic sends text messages to patients and the **Dental check-up due** message is switched on under **Messages to patients**, the patient receives a reminder when the check-up falls due, as long as they agreed to messages at the desk. See [Setting up](02-setting-up.md) for message settings.

Staff who book appointments also see a suggestion in the phone app's **Worth a look** list, such as "3 dental check-ups overdue", for patients whose check-up fell due recently, who have not come back and have nothing booked. Use it to call them and book a chair (see [Appointments and queue](04-appointments-and-queue.md)).

## Dental work on the phone

The phone app has a **Dental** tab on the patient record, and can read and write the dental case sheet of a visit through the same rules as the web. Findings recorded on the phone are kept even with no signal and sent when the connection returns. Marking a procedure done and billing it needs a connection, because billing is checked by the server. See [The mobile app](14-mobile-app.md).

## Good habits

- Work down the case sheet while the patient is in the chair: confirm the medical history first, so a red flag is seen before you inject.
- Chart on the visit page while the patient is in the chair, so findings and charges belong to the right visit.
- Plan the whole course at the first sitting and give the patient the quote. It answers "how much will it cost" before work starts.
- Press **Done & bill** at the end of each sitting, not at the end of the day.
- Set the next check-up before the patient leaves.
