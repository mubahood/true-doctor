# Pharmacy and stock

This chapter is for pharmacists, storekeepers and the Hospital Admin. It covers giving drugs to a patient, keeping the shelves counted, and buying stock from suppliers.

Everything that comes onto a shelf or leaves it is written down as a movement, with the reason, the quantity, who did it and what the shelf held afterwards. Nobody types a stock quantity directly. You change stock by receiving, dispensing, adjusting or writing off, and True-Doctor keeps the count. A shelf can never go below zero: if you try to take out more than is there, True-Doctor refuses and nothing is changed.

## Who can do what

The Pharmacist and the Hospital Admin can see and do everything in this chapter. Other roles do not see the pharmacy menu.

The menu group is called **Pharmacy** in the sidebar. Some practice types give it a different name, such as **Drugs inventory**, and a practice type that does not use a pharmacy hides it. The group holds **Stock items**, **Stock alerts**, **Stock ledger**, **Purchase orders** and **Suppliers**. **Stock categories** is under **Catalogues** in the Administration part of the menu.

## Dispensing to a patient

Drugs are given against a visit, so the stock and the bill always agree.

1. Open the patient's visit. See [Visits and consultation](05-visits-and-consultation.md) for how to find it.
2. Find the **Dispensing** panel and press **Dispense drugs**.
3. In **Drug (from stock)**, search for the item and pick it.
4. Type the quantity in **Qty**.
5. To give more than one drug, press **Add another line** and repeat. Use the bin icon to remove a line.
6. Add a **Note** if you need one.
7. Press **Dispense & bill**.

In one step, each drug comes off its shelf and its charge goes onto the visit at the item's sale price. If any one line has more than the shelf holds, True-Doctor shows the problem on that line and nothing is dispensed, not even the other lines. Correct the quantity and try again.

The **Dispensing** panel then lists what was given and when. Press the date to open the dispensation, which shows each drug, quantity, unit price and the total.

Drugs can also leave the shelf when a clinician adds a product to an order or a charge on the visit. Those are explained in [Visits and consultation](05-visits-and-consultation.md) and [Billing and payments](07-billing-and-payments.md). They move stock in exactly the same way.

## Stock items

**Stock items** is the list of everything the store holds. Across the top you see **Items stocked**, **What it is worth** (at cost), **Running out** and **Expiring**.

Each row shows the item, its category, how much is **On the shelf**, the **Reorder at** level, the **Value**, and the **Batch · expiry**. Use the search box to find an item, and the filter to show **Running out**, **Expiring soon** or **Already expired**.

### Add a new item

1. Press **New item**.
2. Fill in **Name**, **Unit** (for example tablet or box), **Reorder level**, **Cost price** and **Sale price**. These are required.
3. Choose a **Category**, and add the **SKU**, **Batch no.** and **Expiry date** if you have them.
4. Leave **Active** ticked.
5. Press **Create item**.

A new item starts empty. Put the opening quantity on the shelf with **Receive** (below).

To fill a new pharmacy quickly, press **Import samples**, tick the items you stock, adjust the prices and press **Import items**. Items you already have are skipped.

### Receive stock

Use this for a delivery, a donation or an opening count. If you bought the stock on a purchase order, receive it on the order instead (see Purchase orders below) so the delivery is linked to the supplier.

1. Press **Receive** on the item's row.
2. Type **How many**.
3. Under **What it cost**, type either the **Total paid** or the **Cost per** unit. Whichever you type fills in the other, so you do not have to divide. Leave both empty to keep the cost already on file.
4. Under **What it sells for**, type the **Price per** unit. Every dispensation from now on is charged at this price.
5. Check the preview. It shows what the delivery costs, what it brings in if it all sells, and the profit. If the profit shows as a loss, you have probably typed the prices the wrong way round.
6. Add a **Note**, such as the delivery note number, and press **Receive**.

### Adjust stock

Use **Adjust** from the row's actions menu when a count shows the shelf is wrong. Type **How many**, choose **What happened** (for example **Adjustment (in)**, **Adjustment (out)**, **Returned to stock**, **Expired**, **Damaged** or **Lost or stolen**), add a note and press **Record it**. Choose the real reason. "Lost or stolen" and "Expired" are different problems, and the reports keep them apart.

### Write off stock

Use **Write off** for stock that must leave the shelf for good: expired, damaged, contaminated or recalled.

1. Press **Write off** in the row's actions menu.
2. **How many** starts at the whole quantity on the shelf. Lower it only if part of the stock is still good.
3. Choose **What happened**: **Expired**, **Damaged**, **Lost or stolen** or **Wastage**.
4. In **Why**, say what happened. This is required, because it is the record an auditor will read. You can pick a suggestion such as "Expired" or "Broken in transit".
5. Press **Write it off**.

A write off cannot be undone. A mistake is corrected with another movement, such as an adjustment in.

### The item page

Press an item's name for a quick view, or choose **Full record** from the actions menu. The full record shows the item's details, its badges (low stock, expired or expiring), and its **Movement ledger**: the latest movements, with the balance after each one. It also has **Receive** and **Adjust** buttons.

An item that has ever moved cannot be deleted, because its history is evidence. Press **Deactivate** instead: the history is kept and the item no longer appears for new dispensing. An item that has never moved can be removed with **Archive**.

## Stock alerts

**Stock alerts** shows what needs action, worst first, in four lanes:

1. **Out of stock**: nothing left. These cannot be dispensed at all.
2. **Already expired**: out of date but still counted in your stock. Write it off so the figures are true.
3. **Running out**: at or below the reorder level. The row shows how much to order to put it right and what that would cost.
4. **Expiring soon**: expires within 90 days but is still usable, so use it first. Items expiring within 30 days are marked.

Each row has a **Receive** or **Write off** button, and the actions menu has the rest. Use **Every shelf** to show one category only. When nothing needs attention, the page says so.

### Write off several expired items at once

1. In the **Already expired** lane, tick the items, or press **Pick all** to tick every one.
2. Press **Write off the** followed by the number picked, to open **Write off several at once**.
3. Choose **What happened** and type **Why**.
4. Press **Write off**.

Each item still gets its own line in the ledger. If one cannot be written off, for example because it was dispensed while the dialog was open, the others still go through and you are told which one failed.

## Stock ledger

**Stock ledger** is the store's record book: every movement in and out, in the order it happened. Nothing on it can be edited.

Each row shows when, the item, the amount in or out, what the shelf stood at afterwards, the value at cost, the profit or loss, who did it and the note. Filter by text, by **In only** or **Out only**, by reason, and by a date range. To see one item's history, choose **Everything that moved** from that item's actions menu on **Stock items**.

The figures at the top add up only the rows you have filtered:

- **Bought**: what came in.
- **Sold**: what went out to patients.
- **Profit on what sold**: the selling price less what it cost.
- **Lost**: expired, damaged or missing stock, valued at cost.

Each movement keeps the cost and selling price of the day it happened, so changing a price today does not rewrite last month.

## Stock categories

**Stock categories** groups your items, for example tablets, syrups or sundries. Each row shows how many items, how much is **In store**, how many are **Running out** and **Expiring**, and the **Value**. Press any of those counts to see the items behind it. Tick **Needs attention** to show only categories with a problem.

To add one, press **New category**, fill in **Name** and **Counted in** (the unit, such as tablet), add a **Description** if you like, and press **Create category**. **Import samples** offers a starter list. A category that still holds items cannot be deleted; move the items first.

## Suppliers

**Suppliers** lists the companies you buy from. Press **New supplier** and fill in **Name**, and if you have them, **Contact person**, **Phone**, **Email**, **TIN**, **Address**, **Pays within (days)** and **Notes**, then press **Create supplier**. Press a supplier's name for a quick view with their latest orders; **All orders** opens their full list.

## Purchase orders

A purchase order is what you send a supplier. It goes through these steps: **Draft**, **Ordered**, then **Part received** or **Received**. An order can be **Closed** when the rest is not coming, or **Cancelled** if nothing has been received.

### Raise an order

1. Open **Purchase orders** and press **New purchase order**.
2. Choose the **Supplier**. If you have none yet, add one on **Suppliers** first.
3. Set **Expected by** and any **Notes for the supplier**, then press **Start the order**.
4. On the order page, under **What is ordered**, pick an **Item**, type the **Quantity** and **Unit cost**, and press **Add**. Repeat for each item. Use the bin icon to remove a line.
5. When the list is right, press **Mark as ordered**. From now on the lines cannot change.
6. Press **PDF** to print or send the order. It asks the supplier to quote the order number and print the batch and expiry.

The list page shows each order's number, supplier, dates, total and status. Choose **Waiting for goods** to see orders still due.

### Receive goods

1. Open the order and press **Receive goods**.
2. Set **Received on** and the **Supplier invoice or delivery note** number.
3. For each line, type the **Quantity** that actually came, the **Unit cost** from the supplier's invoice, the **Batch** and the **Expiry**. Leave a line at 0 if it did not come; it stays on order.
4. Press **Put on the shelf**.

The stock goes onto the shelf, the invoice cost becomes the item's cost, and the amount owed to the supplier is recorded in the books. The order becomes **Part received** or **Received** by itself. Each delivery appears on the order with its own number. A delivery cannot be edited; a mistake is put right with a return.

When part of an order will never arrive, press **Close**, give a **Reason** and press **Close order**. What already came in stays.

To cancel an order that has received nothing, press **Cancel**, give a **Reason** and press **Cancel order**.

### Return goods to the supplier

1. On the order page, find the delivery and press **Return** on the line.
2. Type the **Quantity** and **Why it is going back**.
3. Press **Return**.

The stock leaves the shelf and the amount owed to the supplier falls by what that delivery cost. You cannot return more than the delivery brought in, or more than is on the shelf.

Paying a supplier is recorded in Finance. See [Finance](13-finance.md).
