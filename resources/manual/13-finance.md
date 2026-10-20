# Finance

True-Doctor keeps your hospital's books for you. Every invoice, payment, card top-up, insurer deposit and stock movement is posted to the books as it happens. You only record by hand what does not come through a patient's bill: rent, salaries, electricity, a grant, cash taken to the bank.

The books are double-entry, so every amount goes out of one account and into another, and the two sides always balance. You do not need to know bookkeeping to record money in and money out. The journal and chart of accounts are there for an accountant who wants them.

The pages are in the **Finance** group of the **Billing & finance** part of the menu.

## Who can do what

| Task | Roles |
|---|---|
| See the overview, transactions, journal, chart of accounts, statements and financial years | Hospital Admin, Accountant |
| Record money in, money out and transfers | Hospital Admin, Accountant |
| Post journals and opening balances, reverse entries, change the chart of accounts, open and close financial years, change finance settings | Hospital Admin, Accountant |

## Finance overview

**Finance overview** is the summary page. Choose a **Period** (**Today**, **This week**, **This month**, **Last month**, **This quarter**, **This year**) to change the figures.

- **Cash in your books**: what you hold today across all cash, bank and mobile-money books.
- **Income**, **Expenses** (including the cost of drugs sold) and **Net profit** or **Net loss** for the period.
- **Your books**: the balance of each money book.
- **Owed and owing**: unpaid bills and insurers owed to you, what you owe suppliers, money held for patients and insurers on cards and floats, tax collected and not yet paid over, and stock on the shelf at cost.
- **Six months: earned and spent**, **Where the money went** and the **Latest entries**.

The **Money in**, **Money out** and **Transfer** buttons at the top open the same forms as on **Transactions**.

If the hospital was using True-Doctor before the books were switched on, the overview may say some records are not in the books yet. Click **Bring them in** to post them. Until you do, balances only count what has happened since.

## Recording money in and money out

Use these for anything that is not a patient payment.

### Money out (an expense)

1. Click **Money out** on **Finance overview** or **Transactions**.
2. Enter the **Date** and **Amount**.
3. In **Paid from**, choose the book the money left (cash drawer, bank, mobile money).
4. In **What it was for**, choose a category, for example an expense such as electricity or salaries.
5. Write a **Description**, for example "October electricity bill".
6. Fill in **Paid to** and **Reference** (receipt, cheque or transaction number) if you have them.
7. Click **Record**.

Every money out is listed in the **Expenditure** report under **Monthly reports**, by kind, by account, by book and by week (see [Reports and ministry forms](12-reports-and-ministry-forms.md)).

### Money in (income not from a patient bill)

1. Click **Money in**.
2. Enter the **Date** and **Amount**.
3. In **Received into**, choose the book.
4. In **What it was**, choose a category, for example a donation, a loan or capital put in.
5. Add a **Description**, **Received from** and **Reference**, then click **Record**.

### Transfer between books

Use **Transfer** when money moves between your own books, for example the day's takings banked.

1. Click **Transfer**.
2. Choose the **From** and **To** books and enter the **Amount** and **Date**.
3. Add a **Note**, then click **Record**.

A transfer is not income or expense. It only moves money.

If no money books are offered, add one first in the **Chart of accounts** with **New book**.

Stock is not bought here. Receive it in the pharmacy so the shelf and the books agree. See [Pharmacy and stock](08-pharmacy-and-stock.md).

## Transactions

**Transactions** is the cashbook: every movement in or out of your money books. Filter by **Book**, **Direction** (**Money in** or **Money out**), **Kind**, and dates, or use the quick periods. Search by description, payee or reference. The page shows the **Balance today** of the chosen book.

Click **CSV** to download the list as a spreadsheet.

## Journal

**Journal** lists every entry in the books, each with its number, date, description, kind and amount. Kinds include **From the system** (posted automatically from invoices, payments, cards and stock), **Money in**, **Money out**, **Transfer**, **Journal**, **Opening balance** and **Reversal**. Click an entry to see its debit and credit lines.

### Post a journal

For adjustments such as depreciation.

1. Click **New journal**.
2. Enter the **Date**, **Reference** and **Description**.
3. For each line, choose the **Account** and enter a **Debit** or a **Credit**. Use **Add a line** for more lines.
4. When debits equal credits, click **Post journal**.

### Opening balances

When you start keeping your books in True-Doctor, enter what the hospital had and owed on that day.

1. Click **Opening balances**.
2. Set **As at**, usually the day before your first transaction here.
3. Enter each account's balance as it normally reads. Use a negative figure for an overdrawn account.
4. Click **Post opening balances**.

What the hospital was worth is worked out for you. Stock is not entered here: it is valued from the stock ledger as items are received. If opening balances were entered before, new ones are added to them, so reverse the earlier entry first if you are replacing it.

### Correcting a mistake

Entries are never edited or deleted. To undo an entry you or a colleague recorded, open it, write why under **Reverse this entry** (for example "Recorded twice") and click **Reverse**. This writes the mirror image dated today. Both stay in the journal.

Entries posted by the system cannot be reversed by hand. Correct the cause instead, for example with a credit note on the invoice (see [Billing and payments](07-billing-and-payments.md)), or post a journal.

## Chart of accounts

**Chart of accounts** lists every account, grouped by kind: **Asset**, **Liability**, **Equity**, **Income**, **Expense**. A standard chart is set up for you, with extra accounts to suit your practice type.

- **New book** adds a place where money is kept: a cash drawer, bank account or mobile-money wallet. It will be offered wherever money is received or paid.
- **New account** adds any other account, for example "Generator fuel" as an expense. Enter the **Kind**, **Code** (its place in the chart), **Name** and **Description**.
- You can edit, switch off or delete an account. Accounts the system posts to cannot be switched off or deleted. An account with entries cannot be deleted. Tick **Show switched-off** to see them.

Click an account to see its ledger for a period: **Opening balance**, every line with the balance after, and **Closing balance**. Click **CSV** to download it.

To make a service on the price list earn into its own income account, set it on the **Price list**. See [Setting up your hospital](02-setting-up.md).

## Financial statements

**Financial statements** are read live from the books. Choose a statement:

| Statement | What it shows |
|---|---|
| **Income statement** | Income, discounts, cost of sales, running costs and net profit for the period. You can compare with the period before or a year earlier. |
| **Balance sheet** | Assets, liabilities and equity on the last day of the period. |
| **Trial balance** | Every account's balance in its debit or credit column. |
| **Cash flow** | Money in and out of your books by what it was for, and each book's start, in, out and end. |

Pick a period or type the dates. Click **PDF** to print or **CSV** to download.

## Financial years

A financial year is an accounting period. Closing one stops anything new from being dated inside it, so figures you have reported cannot change.

### Create a period

1. Open **Financial years** and click **New period**.
2. Enter a **Name** (for example "FY 2026"), **Starts on** and **Ends on**. Periods cannot overlap.
3. Click **Create period**.

### Close or reopen

Click **Close** on the period (or **Close period** on its page) and confirm. New invoices and entries dated inside it are then refused. If you must correct something, click **Reopen** (or **Reopen period**). Postings into it are allowed again.

Open a period to see its report: **Payments received**, **Invoiced**, **Outstanding** (unpaid balance on invoices issued in the period) and **Payments by method**.

## Finance settings

**Finance settings** decides which account the hospital's own work lands in. Anything left on **Standard** uses the account shown. Changes apply from the next posting; nothing already in the books moves.

- **Where payments land**: the book each payment method goes into, for example cash to the cash drawer and mobile money to the mobile-money wallet. Card and insurance payments are handled for you.
- **Cards and insurers**: **Card top-ups without a payment method** and **Insurer deposits**.
- **Stock deliveries**: **When stock is received, it is…** owed to suppliers (pay them later with **Money out**) or paid on delivery from a book you choose.

Click **Save settings**.
