# API: finance, cards and insurance

Base `/api/v1`, Sanctum bearer token, the standard envelope (`{success, code, message, data, errors}`;
lists add `meta` via `ApiResponse::paginated`). Every endpoint is authorised **exactly as its web
page** and calls the same services, so the figures cannot drift from the screens. Money is always a
decimal string with two places (`"37999.50"`). Lists paginate (`?per_page=10|20|50|100`, `?page=`) and
take `?q=` where the web page has a search box. Routes live in `routes/api/finance.php`; tests in
`tests/Feature/Api/{FinanceApiTest,CardApiTest,InsuranceApiTest}.php`.

Errors: `403 forbidden` (missing permission, from the Policy / permission check), `404 not_found`
(unknown id, **or another hospital's** - tenancy is by global scope; a user with no hospital also
gets 404 on the books), `422 validation_failed` (shape, or a business rule from the service: closed
period, unbalanced journal, empty card, illegal claim move; `errors.<field>` names the input).

Permissions: `finance.view` (read the books), `finance.record` (money in / out / transfer),
`finance.manage` (journals, reversals, chart, settings, financial years, catch-up),
`patients.card.manage` (all cards), `insurance.view` / `insurance.manage` (claims, insurers).

## Finance overview and pickers

| Method | Path | Permission |
|---|---|---|
| GET | `/finance?range=today\|week\|month\|last-month\|quarter\|year` | finance.view |
| GET | `/finance/options` | finance.view |
| POST | `/finance/catch-up` | finance.manage |
| GET | `/finance/settings` | finance.manage |
| PUT | `/finance/settings` | finance.manage |

`GET /finance` data: `range`, `presets`, `from`, `to`, `income_statement` (the statement shape below),
`books[{id,code,name,balance}]`, `cash` (all books), `receivables`, `payables`, `inventory`,
`deposits`, `tax`, `trend[{month,income,expenses}]` (six months, oldest first - the chart series),
`top_expenses[{name,amount}]` (the pie/bars), `recent[]` (8 journal entries with lines), `pending`
(`{kind: count}` of records not yet in the books), `can{record,manage}`.

`GET /finance/options`: `books[]`, `money_categories{in|out: {group: {id: label}}}`,
`journal_accounts{group: [{id,label}]}`, `opening_accounts[]`, `has_opening`, `journal_kinds`,
`account_types`, `next_account_codes{type: code}`, `presets`, `reports[{key,title}]`.

`POST /finance/catch-up` -> `{posted, pending}` ("Bring the books up to date").

Settings: `GET` -> `methods[{method,label,book_id}]`, `card_topup_book`, `insurer_deposit_book`,
`stock_credit_account` (account ids or null = default), `defaults`, `books[]`, `credit_accounts[]`.
`PUT` body (all optional; null clears to the default): `method_books{cash|mobile_money|bank|...: id}`,
`card_topup_book`, `insurer_deposit_book`, `stock_credit_account`; ids must be active books (the
stock account may also be a liability). Returns the same shape as GET.

## Transactions (the cashbook)

`GET /finance/transactions` (finance.view). Filters: `book` (account id), `kind`
(`system|income|expense|transfer|journal|opening|reversal`), `dir` (`in|out`), `from`, `to`, `q`.
Row: `{id,date,book{id,name},in,out,entry{uuid,entry_no,kind,kind_label,memo,party,reference,is_reversed,counter[]}}`.
Meta: `page_in`, `page_out`, `book_balance` (with `book`).

`POST /finance/transactions` (finance.record) -> 201 journal entry (see Journal). Body:

```json
{ "kind": "in|out", "date": "2026-10-16", "book_id": 1, "account_id": 12, "amount": "120000.50",
  "memo": "Generator fuel", "party": "Shell", "reference": "INV-9" }
{ "kind": "transfer", "date": "2026-10-16", "from_book_id": 1, "to_book_id": 3, "amount": "100000", "memo": null }
```
422 if the book/category is wrong, the period is closed, or both books are the same.

## Journal and chart of accounts

| Method | Path | Permission |
|---|---|---|
| GET | `/finance/journal?kind&from&to&q` | finance.view |
| GET | `/finance/journal/{entry-uuid}` | finance.view |
| POST | `/finance/journal` | finance.manage |
| POST | `/finance/journal/opening` | finance.manage |
| POST | `/finance/journal/{uuid}/reverse` | finance.manage |
| GET | `/finance/accounts?type&q&off=1` | finance.view |
| GET | `/finance/accounts/{id}?from&to` | finance.view |
| POST | `/finance/accounts` | finance.manage |
| PUT | `/finance/accounts/{id}` | finance.manage |
| POST | `/finance/accounts/{id}/toggle` | finance.manage |
| DELETE | `/finance/accounts/{id}` | finance.manage |

Entry: `{uuid,entry_no,date,kind,kind_label,memo,party,reference,total,is_reversed,can_be_reversed,lines_count|lines[{account{id,code,name},debit,credit,memo}],created_by,reverses,reversed_by,source}`.

- `POST /finance/journal` `{date, memo, reference?, lines:[{account_id, debit?, credit?, memo?}]}`: at least 2 lines, must balance.
- `POST /finance/journal/opening` `{date, balances:{"<account id>": "75000"}}`.
- `POST .../reverse` `{reason}`: only an entry a person wrote, once (system entries are undone by undoing their cause).
- Chart (`GET /finance/accounts`) returns groups `[{type,label,accounts:[{id,code,name,label,type,subtype,system_key,is_system,is_book,is_active,description,balance,entries}]}]`.
  `POST/PUT` body `{code (unique, alnum/.-), name, type: asset|liability|equity|income|expense, is_book?, description?, is_active?}`.
  A system account cannot be switched off or change kind; an account with entries cannot change kind; delete only if unused (422 otherwise).
- `GET /finance/accounts/{id}` -> `{account, from, to, opening, closing, debit, credit, lines[{date,entry_uuid,entry_no,memo,party,reference,debit,credit,balance}]}`.

## Financial statements

`GET /finance/statements?report=income|balance|trial|cashflow&preset=month|...&from&to&vs=none|previous|year` (finance.view).
Default report income, default period this month, `vs` applies to the income statement.
Balance sheet and trial balance are "as at" `to`.

```json
{ "report": "income", "period_label": "01 Oct 2026 – 31 Oct 2026", "compare": {"from":"..","to":"..","vs":"previous"},
  "statement": { "report":"income","title":"Income statement","from":"2026-10-01","to":"2026-10-31",
    "sections":[{"key":"revenue","title":"Income","rows":[{"account_id":7,"code":"4000","name":"Consultations","amount":"90000.00","compare":null}],"total":"90000.00","compare_total":null}, "..."],
    "totals":[{"key":"net_revenue","label":"Net income","amount":"90000.00","compare":null},{"key":"gross_profit",...},{"key":"operating_profit",...},{"key":"net_profit",...}],
    "margin":"100.0" } }
```
Other shapes: `balance` (sections current_assets, fixed_assets, current_liabilities, long_term_liabilities, equity; totals incl. `assets`, `liabilities_and_equity`; `balanced`),
`trial` (one section `accounts` with `debit`/`credit` per row; totals `debit`,`credit`; `balanced`),
`cashflow` (sections `inflows`, `outflows` as `{label,amount}` rows with `total`, and `books` rows `{opening,in,out,closing}`; totals opening/in/out/closing).

Downloads (the web's own, same check): `GET /finance/statements/pdf?report&from&to&vs` (PDF),
`GET /finance/export?what=statement|ledger|transactions&report&from&to&vs&account&book&dir&kind&q` (CSV).

## Financial years

| Method | Path | Permission |
|---|---|---|
| GET | `/financial-years?q` | finance.view |
| GET | `/financial-years/{id}` | finance.view (adds `report{payments_total,invoices_total,outstanding,by_method,invoice_count,payment_count}`) |
| POST | `/financial-years` `{name, starts_on, ends_on}` | finance.manage (422 on overlap / duplicate name) |
| POST | `/financial-years/{id}/close` | finance.manage (422 if already closed) |
| POST | `/financial-years/{id}/reopen` | finance.manage |

Item: `{id,name,starts_on,ends_on,status: open|closed,status_label,is_open,closed_at,closed_by}`. A closed period refuses postings dated inside it (422 on money, card and payment endpoints).

## Cards

All `patients.card.manage`. The card number is never returned; cards show `masked` (`•••• 4821`) and `last4`.
Card: `{uuid,masked,last4,status,status_label,can_transact,balance,accepts_credit,max_credit,expiry,is_expired,is_insurance,member_no,patient{uuid,name,patient_no},provider{id,name},issued_by,active_holders}`; detail adds `holders[]` and `proof{balance,ledger,agrees}`.

| Method | Path | Notes |
|---|---|---|
| GET | `/cards?status&insurer=insured\|own\|<id>&owing=1&q&sort=balance\|created_at\|expiry&dir` | meta `totals{cards,held,owed}` of the filtered list |
| GET | `/cards/options` | statuses, relationships, `top_up_methods`, active `insurers` |
| POST | `/cards/lookup` `{number}` | find by full number (hash lookup); 404 if not this hospital's; throttled 30/min |
| GET | `/cards/{uuid}` | detail + holders + proof |
| GET | `/cards/{uuid}/records` | the card's ledger, 20/page |
| POST | `/cards/{uuid}/transactions` `{type: credit\|debit, amount, description?, paid_by?: cash\|mobile_money\|bank}` | 201 `{record, card}`; 422 for overdraft, suspended/expired card, closed period, >2 decimals |
| PUT | `/cards/{uuid}/terms` `{status, accepts_credit?, max_credit?, expiry?, insurance_provider_id?, member_no?}` | |
| POST | `/cards/{uuid}/holders` `{patient_uuid, relationship}` | |
| DELETE | `/cards/{uuid}/holders/{holderId}` | revokes (history stays) |
| POST | `/cards/{uuid}/settle` | also needs `insurance.manage`; `data.settled` is `"0.00"` when nothing was owed |
| GET | `/patients/{patient-uuid}/cards` | own cards and those the patient is a holder on |
| POST | `/patients/{patient-uuid}/cards` `{expiry?, accepts_credit?, max_credit?, insurance_provider_id?, member_no?}` | 201 |
| GET | `/card-records?type=credit\|debit&insurer&from&to&q` | hospital-wide ledger; meta `totals{in,out,net}` |
| GET | `/card-records/{uuid}` | one line with card, patient and insurer settlement |

Record: `{uuid,type,type_label,amount,signed_amount,balance_after,description,reference,patient,card,settlement,created_by,created_at}`.

## Insurance

| Method | Path | Permission |
|---|---|---|
| GET | `/insurance-claims?status&q` | insurance.view (meta `statuses`) |
| GET | `/insurance-claims/{uuid}` | insurance.view |
| POST | `/insurance-claims` `{patient_uuid, invoice_uuid, insurance_provider_id, amount, notes?}` | insurance.manage -> 201 Draft |
| POST | `/insurance-claims/{uuid}/transition` `{status, note?}` | insurance.manage |
| GET | `/insurance-providers?q` | insurance.view |
| POST | `/insurance-providers` | insurance.manage |
| PUT | `/insurance-providers/{id}` | insurance.manage |
| GET | `/insurance-providers/{id}?from&to` | insurance.view |
| GET | `/insurance-providers/{id}/ledger` | insurance.view (20/page) |
| GET | `/insurance-providers/{id}/usage?from&to` | insurance.view (the web's usage PDF) |
| POST | `/insurance-providers/{id}/deposit` `{amount, reference?, notes?}` | insurance.manage |
| POST | `/insurance-providers/{id}/adjust` `{direction: add\|remove, amount, reason}` | insurance.manage (cannot go below zero) |
| POST | `/insurance-providers/{id}/settle` | insurance.manage: clears what the float covers; `{settled,paid,unpaid,float}` |
| POST | `/insurance-providers/{id}/cards/{card-uuid}/settle` | insurance.manage |

Claim: `{uuid,claim_no,status,status_label,badge,is_terminal,next_statuses[{value,label}],amount,notes,patient,provider,invoice{uuid,invoice_no,total,balance},resolved_by,submitted_at,resolved_at,created_at}`.
Lifecycle (ClaimStatus): draft -> submitted | cancelled; submitted -> approved | rejected; approved -> paid | rejected. Reaching `paid` records an insurance payment on the invoice (the smaller of the claim and the invoice balance); illegal moves are 422 on `status`.
Provider: `{id,name,code,contact_person,contact_phone,contact_email,is_active,float_balance,default_credit_limit}`; show adds `figures{cards,in_debt,owed,shortfall}`, `proof{float,ledger,agrees}`, `cards[]` (in debt first, max 200), `recent[]`, `usage{from,to,members[],spent,refunded,net,deposits,settlements,float,outstanding}`, `can_manage`. Creating a provider with the name of an archived one restores it (as the web does); omitting `is_active` on create/update leaves it on.

## Not exposed

- Card refunds: the web has none either (a refund is a credit top-up with a description).
- CSV of the card/claim lists, provider delete (the web's CrudModal delete) and the bulk "Backfill books" beyond `catch-up`.
