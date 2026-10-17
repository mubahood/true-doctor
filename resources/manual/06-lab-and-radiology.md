# Lab and radiology

This chapter is for laboratory and imaging staff, and for the doctors who read their results. It covers the two work lists, **Lab orders** and **Radiology orders**, and the two catalogues behind them, **Lab tests** and **Radiology studies**.

A doctor asks for a test or a scan from inside the visit. How to do that is in [Visits and consultation](05-visits-and-consultation.md). Once it is asked for, the order appears on the lab or radiology list straight away, and the price of each test or study is already on the patient's bill. You do not need to bill it again. Payments are covered in [Billing and payments](07-billing-and-payments.md).

## Who can do what

| Role | Lab | Radiology |
|---|---|---|
| Hospital Admin | Everything | Everything |
| Doctor | See orders and results, order tests | See orders and reports, order studies |
| Lab Technician | See orders, enter results, manage the **Lab tests** catalogue | |
| Radiologist | | See orders, write reports, manage the **Radiology studies** catalogue |

You find **Lab orders** and **Radiology orders** in the sidebar under **Diagnostics**. The catalogues are under **Catalogues** in the Administration part of the menu. If your hospital's practice type does not use a laboratory or radiology, these menu entries do not appear.

## Lab orders

Open **Lab orders**. Across the top are four counts:

- **Awaiting collection**: requested, no sample taken yet.
- **Collected**: the sample is in, work not started.
- **On the bench**: being processed. Underneath it says how long the oldest unfinished order has been waiting, in hours.
- **Reported today**: results handed back today.

Below the counts is one row per order: when it was requested, the patient, how many tests, how many files are attached, and the status. If an order has been waiting 24 hours or more and is not finished, the number of hours shows beside its status.

To narrow the list:

- Type a patient's name or number in the search box.
- Pick a status from **All statuses**.
- Tick **Outstanding only** to see only orders that still owe a result.
- Press **Clear** to remove all filters.

### The steps an order goes through

A lab order moves in this order: **Ordered**, **Collected**, **Processing**, **Completed**. It can be **Cancelled** at any point before it is completed. Completed and cancelled orders are final.

To move an order one step, open the row's actions menu (the three dots at the end of the row) and pick the next step under **Move to**. For example, when you take the sample, move it to **Collected**.

### Enter a result

1. On the order's row, press **Enter result**. A dialog opens over the list.
2. At the top you see when it was asked for, who asked, the visit number and any clinical notes from the doctor.
3. Under **What came back**, each test has its own row. The normal range is shown under the test name. For each test, type the **Value**, choose a **Flag** (Normal, Low, High or Abnormal) and add a **Note** if needed.
4. If the work used extra items, such as reagents, tubes or a repeat run, add them under **What the test used**. Search for a service in **Add a service** or a product in **Add a product off the shelf**, then set the quantity. These are charged to the same visit as the tests. A product added here also comes off the pharmacy shelf. If you add nothing, the patient pays only for the tests that were ordered.
5. Under **Files**, drop the analyser printout, a photo of the slide or the signed request, or click to choose them. PDF, images, text and document files up to 10 MB each are accepted.
6. Choose how to finish:
   - **Save** keeps what you typed and leaves the order open. Use this if you are part way through.
   - **Save and report back** saves the results and completes the order in one step, even if it was not yet moved to Collected or Processing.

When an order is completed, the doctor who ordered it is notified that the result is out.

To look at a finished order again, press **Open result** on its row.

### Other actions on a lab order

The row's actions menu also has:

- **Result and files**: opens the same result dialog.
- **Full record**: opens the order on its own page, with a **PDF** button and the step buttons under **Advance**.
- **Printable result**: opens the printed result sheet in a new tab. Abnormal values are highlighted.
- **The visit it came from**: opens the visit.

On the full record page you can also press **Send to patient**. This creates a link the patient opens on their phone with a code or their date of birth. Patient links are explained in [Patients](03-patients.md).

## Radiology orders

**Radiology orders** works the same way as the lab list, with a written report instead of test values.

The counts across the top are:

- **Requested**: not booked in yet.
- **Booked in**: waiting for the room.
- **Awaiting a report**: the study is done and needs reading. Underneath it shows how long the oldest has waited.
- **Reported today**: signed off today.

The search box, **All statuses**, **Outstanding only** and **Clear** work as on the lab list. The **Images** column shows how many files are attached.

### The steps a radiology order goes through

**Ordered**, **Scheduled**, **Performed**, **Reported**. It can be **Cancelled** before it is reported.

Use **Move to** in the row's actions menu to book the patient in (**Scheduled**) and to record that the study was done (**Performed**). A report can only be signed off once the order is at **Performed**.

### Write a report

1. On the order's row, press **Report**. A dialog opens over the list, showing the studies asked for and the clinical notes.
2. In **Findings**, describe what the images show: technique, comparison, what is seen.
3. In **Impression**, write the conclusion the referring doctor will act on.
4. Under **What the study used**, add any contrast, film or repeat exposure. These are charged to the visit in the same way as on the lab dialog.
5. Under **Images and files**, drop the films, the printed report or the signed request.
6. Press **Save** to keep a draft, or **Save and sign off** to save the report and mark the order **Reported**.

Once signed off, the dialog shows who signed it and when. Press **Open report** on the row to read it again.

The row's actions menu also has **Report and images**, **Full record**, **Printable report** and **The visit it came from**. The full record page has a **PDF** button and **Send to patient**.

## The Lab tests catalogue

**Lab tests** is the list of tests your laboratory offers, with their prices. Doctors pick from this list when they order. The Lab Technician and the Hospital Admin can change it.

### Add a test

1. Open **Lab tests** and press **New test**.
2. Fill in **Name** and **Price**. These are required.
3. Add the **Code**, **Specimen** (for example, Blood), **Unit** and **Reference range** if you have them. The range is printed beside each result so the reader can compare.
4. Leave **Active** ticked so the test can be ordered.
5. Press **Create test**.

To start quickly, press **Import samples**. A list of common tests appears. Tick the ones you want, change the prices to your own, and press **Import tests**. Tests you already have are skipped.

To change a test, press the pen icon on its row. To stop offering a test, press the archive icon. The eye icon opens a quick view of the test.

Changing a price does not change orders already placed. Each order keeps the name, price and range the test had when it was ordered.

## The Radiology studies catalogue

**Radiology studies** works the same way. Press **New study** and fill in **Name**, **Modality** (for example X-Ray, CT, MRI or Ultrasound), **Body part** and **Price**. **Import samples** offers a starter list, which you import with **Import studies**. Use the pen icon to edit and the archive icon to stop offering a study.
