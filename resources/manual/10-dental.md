# Dental

This chapter is for dentists, dental assistants and the front desk of a clinic that does dentistry. True-Doctor keeps a chart of every tooth, a treatment plan priced from your own price list, and the date of each patient's next check-up. A procedure marked done is billed on the visit at the same moment, so nothing is forgotten and nothing is billed twice.

Dental work is switched on by your practice type. A dental clinic has it from the start. A hospital or medical clinic gets it when dentistry is one of the specialties chosen under **Your practice** (see [Setting up](02-setting-up.md)). If dentistry is not part of your practice, you will not see any of the screens below.

## Where you find the dental chart

The chart appears in two places, and it is the same chart in both.

- **On the patient record:** open the patient and choose the **Dental** tab. It sits next to the clinical history.
- **On a visit:** open the visit. In a dental clinic the chart shows on every visit. In a hospital that also does dentistry, it shows by itself for a visit in a dental department, or for a patient whose teeth are already charted. On any other visit you see a **Dental chart** card with an **Open the chart** button; press it when you need it.

Work on the visit page when the patient is in the chair. Findings recorded there are linked to that visit, and finished procedures are billed on it.

The **Dental** tab shows on the patient record when your clinic is a dental clinic, when you have an active department with "dent" in its name, or when the patient already has a chart or a plan.

### Who can do what

| Task | Who |
|---|---|
| See the chart and the plan | Doctor, Nurse, Hospital Admin |
| Record findings and plan procedures | Doctor, Hospital Admin |
| Accept, start, mark done or cancel a procedure | Doctor, Nurse, Hospital Admin |
| Set the next check-up | Doctor, Nurse, Hospital Admin |

A dentist signs in with the Doctor role. Other roles do not see the dental chart.

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

The phone app has a **Dental** tab on the patient record. Findings recorded on the phone are kept even with no signal and sent when the connection returns. Marking a procedure done and billing it needs a connection, because billing is checked by the server. See [The mobile app](14-mobile-app.md).

## Good habits

- Chart on the visit page while the patient is in the chair, so findings and charges belong to the right visit.
- Plan the whole course at the first sitting and give the patient the quote. It answers "how much will it cost" before work starts.
- Press **Done & bill** at the end of each sitting, not at the end of the day.
- Set the next check-up before the patient leaves.
