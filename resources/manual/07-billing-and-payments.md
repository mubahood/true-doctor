# Billing and payments

This chapter follows the money for one visit: the charges that build up the bill, the invoice, the payment and its receipt. It then covers prepaid and insurance cards, insurance claims, refunds and credit notes, and the cashier's end-of-day count.

Your currency, tax, invoice number prefix and receipt printer width are set once on **Billing settings**. See [Setting up your hospital](02-setting-up.md). Prices come from the **Price list**, also covered there.

## Who can do what

| Task | Roles |
|---|---|
| See invoices and the cashier's day | Hospital Admin, Doctor, Receptionist, Accountant |
| Add charges, raise invoices, take payments, ask for a refund or credit note | Hospital Admin, Doctor, Receptionist, Accountant |
| Approve or reject refunds and credit notes | Hospital Admin, Accountant |
| Issue cards, top up, charge, change card terms | Hospital Admin, Receptionist, Accountant |
| See insurance claims and providers | Hospital Admin, Receptionist, Accountant |
| Create claims, move them along, take insurer deposits | Hospital Admin, Accountant |

The **Insurance claims** and **Cards** menus only appear if your hospital uses those modules.

## How a bill builds up

You rarely type a charge by hand. When a doctor orders a lab test, an X-ray, a procedure or a drug, the order puts its own line on the visit's bill at the price-list price. See [Visits and consultation](05-visits-and-consultation.md) and [Lab and radiology](06-lab-and-radiology.md).

To see the bill, open the visit and go to the **Bill** section. Each line shows the **Charge**, who **Raised by** it (the order behind it), the **Unit** price, the **Qty** and the **Line total**. Under the lines you see **Charges**, any **Discount**, the tax and the **Total** (or **Due** when there is a discount).

A product given without billing shows on the bill marked **Not billed**, with no amount, and is left off the invoice.

A charge is changed on the order that raised it, not on the bill. Click the order name in the **Raised by** column to open it. This keeps the bill, the work done and the pharmacy shelf in agreement.

If you think a line looks wrong, click **Check the bill adds up**. True-Doctor recalculates each line from its own price and quantity.

### Add a charge by hand

Use this for something sold at the counter that no order raised.

1. In the **Bill** section, click **Add charge**.
2. Choose **Service** (from the price list) or **Product** (comes off the pharmacy shelf).
3. Search for the item. For a product, you see how many are on the shelf, and you choose **Billed** or **Not billed**. Not billed still takes it off the shelf but leaves it off the bill. See [Pharmacy and stock](08-pharmacy-and-stock.md#billed-or-not-billed).
4. If the service has a price range, type the **Price** within the range shown.
5. Enter the **Quantity**. The **Total** is worked out for you.
6. Add a **Note** if you want, then click **Add to bill**.

The charge creates its own order, so every line on the bill has a piece of work behind it.

### Give a discount

1. In the **Bill** section, click **Add discount** (or **Edit discount**).
2. Choose **Fixed amount** or **Percentage**. A percentage follows the bill as lines are added or removed. A fixed amount stays as agreed.
3. Enter the **Amount off** or **Percentage**, and **Why** (for example, staff rate or hardship).
4. Check the preview, then click **Save discount**.

Your name is recorded against the discount. Tax is charged on what is actually paid, after the discount.

## Raising the invoice

When the work is done and the bill is right, raise the invoice.

1. In the **Bill** section, click **Generate invoice**.
2. Check **Charges**, **Discount**, tax and **To invoice**.
3. Click **Raise invoice**.

The invoice number follows your hospital's prefix and the year. From this moment the lines are fixed on the invoice. Each visit has one invoice.

If more work is charged to the visit after the invoice is raised (a bed charge at discharge, for example), the **Bill** section warns you that this amount is on no invoice and nobody has been asked to pay it.

Click **Invoice PDF** to open a printable invoice in a new tab.

## Taking a payment

You can take payment from the visit or from the invoice page.

### From the visit

1. Open the visit and go to the **Payments** section. You see **Invoiced**, **Paid** and **Balance**.
2. Click **Take payment**.
3. Under **How is it being paid?**, choose **Cash**, **Prepaid card**, **Mobile money**, **Bank transfer** or **Insurance**.
4. For **Mobile money**, **Bank transfer** or **Insurance**, enter the **Reference**: the transaction ID from the phone, the slip or transfer number, or the claim or authorisation number.
5. For **Prepaid card**, choose the **Card**. Its balance shows under the field. Cards that are not active or have expired cannot be chosen.
6. Enter the **Amount**, or click **Full balance**.
7. Click **Record payment**.

A part payment is fine. The visit closes itself once the balance reaches zero. True-Doctor will not let you take more than the balance.

### From the invoice page

1. Open **Invoices** from the **Billing** menu and click the invoice number.
2. In **Take payment**, click **Record payment**.
3. Choose the **Method**, enter the **Amount** and a **Reference / note** (receipt book number, transaction ID or a short note).
4. Click **Record payment**.

The invoice page also has **Pay online (Flutterwave)** for card, mobile money, bank or USSD payment online. The payment is recorded only after it is confirmed, so do not take it again at the counter while you wait.

### Invoice statuses

| Status | Meaning |
|---|---|
| Issued | Raised, nothing paid yet |
| Partially paid | Some paid, a balance remains |
| Paid | Settled in full (or credited down to nothing) |
| Void | Cancelled; it no longer asks for money |

## Receipts

Every payment has its own receipt.

- Click **Receipt** next to a payment (in the visit's **Payments** section, or the receipt icon on the invoice page). A full-page receipt opens in a new tab for printing or saving.
- If your hospital has a receipt printer set on **Billing settings**, a print icon also appears next to each payment. It opens a narrow receipt sized for 58 or 80 mm roll paper, ready to print on the counter printer.

The receipt shows your hospital's currency, the lines, the total, the method and any reference.

## Finding invoices

**Invoices** (under **Billing**) lists every invoice with its **Total**, **Balance**, **Status** and date. Search by invoice number or patient, and filter by status. Click the invoice number for a quick view, or use the actions menu for **Open the invoice** or **Printable**.

A patient's own invoices are also on the **Billing** tab of their record. See [Patients](03-patients.md).

## Refunds and credit notes

There are two documents for giving money back.

- A **Credit note** takes money off what an invoice asks for. Use it for a test not done, a line charged twice, or a drug returned unopened. No cash moves.
- A **Refund** pays money back to the patient. You can only refund what the patient paid beyond what the invoice now asks for. So you always issue the credit note first, then the refund.

### Ask for a credit note or refund

1. Open the invoice page.
2. In **Credit notes and refunds**, click **Credit note** or **Refund**. The window shows how much **can be credited** or **can be refunded**.
3. Enter the **Amount**.
4. For a refund, choose **Paid back as** (cash, mobile money, bank transfer or prepaid card). For a card, choose the card. Add the **Reference** for mobile money or a transfer.
5. Enter the **Reason**. It is printed on the document and kept in the books.
6. Click **Save**.

If you are not allowed to approve refunds, the request waits as **Waiting for approval**. If a Hospital Admin or Accountant makes the request, it is approved at once.

### Approve or reject

1. Open **Refunds and credit notes** from the **Billing** menu. It shows how many are waiting.
2. Filter by status or kind if you need to.
3. Use **Approve** or **Reject** on the row (or on the invoice page). Rejecting needs a reason in **Why**.

On approval the document gets its number, the invoice's balance changes, a refund to a card goes back onto the card, and the books are updated. A decision is final. If something was wrong, put it right with another document. Approved documents have a **Printable** PDF.

## Cards

A card lets a patient be treated without paying at the counter each time. It can be a prepaid card the patient tops up, or an insurance card that an insurer stands behind.

### Issue a card

1. Open the patient's record and go to the **Records** tab. Find the **Cards** panel.
2. Click **Issue card**.
3. Set an **Expiry** if you want one. Leave it blank for a card that never expires.
4. Tick **Allow credit / overdraft** if the card may go into debt, and enter the **Credit limit**.
5. Under **Insurer**, leave **The patient's own card**, or pick an insurer. For an insurer, enter the **Member number** the insurer uses.
6. Click **Issue card**.

### Top up or charge a card

1. In the patient's **Cards** panel, click the up arrow (top up) or down arrow (charge) on the card. On the card's own page, use **Top up** or **Charge**.
2. Enter the **Amount**. For a top-up, choose **Paid by** (cash, mobile money or bank transfer).
3. Enter a note in **What for** (or **Note**). Whoever reads the ledger next only has these words.
4. Click **Top up card** or **Charge card**.

To pay an invoice from a card, use **Prepaid card** when taking the payment. Do not charge the card separately as well.

### The card page

Click a card to open its page. It shows the **Balance**, **Credit limit**, who it is **Issued to** and the **Insurer**.

- **Terms**: change the **Status** (an inactive or suspended card takes no money in or out), **Expires**, **Let this card go into debt** and **How far into debt**, the **Insurer** and **Member number**. You cannot lower the limit below what the card already owes.
- **Who may use this card**: click **Add someone**, pick the **Patient** and **Relationship**, then **Add to card**. Family members can then spend the whole balance, so add only the people the cardholder means to. **Remove** takes someone off; what they already spent stays on the ledger.
- **Ledger**: every movement, with who it was **Spent on** and the balance after.
- For an insurance card in debt, **Clear from their float** pays the debt from the insurer's deposit.

### Cards and Card records pages

**Cards** lists every card in the hospital with totals **Held on card** and **Owed to the hospital**. Filter by status, by insurer or **The patient's own**, or tick **In debt only**.

**Card records** lists every movement on every card, with **Money in**, **Money out** and **Net**. Filter by type, card, insurer and dates.

## Insurance

### Insurance providers

Open **Insurance providers** under **Catalogues**. Click **New provider** and enter the **Name**, **Code**, **Contact person**, **Contact phone** and **Contact email**. Keep **Active** ticked.

A provider's page shows the **Float held** (money the insurer has deposited and not yet spent), what **Members owe**, the number of **Member cards** and the **Default limit**.

- **Record a deposit** when the insurer pays you. Enter the **Amount** and their **Bank reference**.
- **Clear the members** pays every member card's debt from the float, oldest debt first, as far as the float goes. **Clear** on a single card does one.
- **Correct the float** puts money on or takes it off, with a reason in **Why**. Use it only to fix a mistake.
- **Usage** shows what each member spent between two dates. Click **Statement PDF** to send it to the insurer.

### Patient coverage

On the patient's **Records** tab, the **Insurance coverage** panel lists their insurers. Click **Add coverage** (Hospital Admin or Accountant), choose the **Provider**, enter the **Member no.**, **Coverage %**, **Valid from** and **Valid to**, then click **Add coverage**.

### Insurance claims

A claim asks an insurer to pay one invoice.

1. Open **Insurance claims** under **Billing** and click **New claim**.
2. Choose the **Patient**, **Insurance provider** and **Invoice**. Only issued or partially paid invoices can be claimed.
3. Enter the **Claim amount** and any **Notes**, then click **Create claim**.

Open the claim and use the buttons under **Advance** to move it along: **Submitted**, then **Approved**, then **Paid**. A draft can be **Cancelled**; a submitted or approved claim can be **Rejected**. Give a **Reason** when you reject or cancel. These steps cannot be undone.

When you mark a claim **Paid**, True-Doctor records an **Insurance** payment for the claim amount on the linked invoice. If the claim covers only part of the bill, the rest stays as a balance for the patient.

## Cashier's day

**Cashier's day** under **Billing** shows one day's takings. Use the arrows or the date box to change day, and **Today** to come back.

- **Received**: total taken and the number of payments.
- **Invoices raised** and **Voided**. Check each voided invoice has a reason.
- **Refunded**, with **Net received** and the number of credit notes.
- **By method**: count the cash drawer against the **Cash** line. Check mobile money and bank against their statements.
- **By person**: what each staff member received.

Click **Print the day** for a PDF to file with the cash.

For the hospital's full accounts, see [Finance](13-finance.md).
