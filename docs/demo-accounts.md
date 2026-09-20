# Demo / test accounts (local only)

Two seeders, both **only when `APP_ENV` is `local` or `demo`** (never production; C14) and both
idempotent — running them twice tops up rather than doubling anything.

| Seeder | What it makes | How long |
|---|---|---|
| `DemoSeeder` | Two tenants, one account per role, one of everything | seconds |
| `DemoPopulationSeeder` | Three months of work behind Hospital A — 58 people on the register, ~60 episodes of care, a diary, wards, a price list, stock | about a minute |

Run both with **`php artisan demo:seed`** (or `migrate:fresh --seed`, which also rebuilds the
database). `php artisan demo:seed --accounts-only` skips the slow half.

`DemoSeeder` alone is what the test suite seeds (`SmokeRoutesTest`), which is why the volume
lives in a separate class: the smoke walk needs a row in every module, not sixty visits.

**Password for every account: `password1`**

## Accounts

| # | Email | Role | Hospital | On the demo rail |
|---|---|---|---|---|
| **1** | `admin@gmail.com` | **super_admin** | SaaS central | **no** — see below |
| 2 | `admin.a@test.com` | hospital_admin | General Hospital A | yes |
| 3 | `doctor.a@test.com` | doctor | General Hospital A | yes |
| 4 | `nurse.a@test.com` | nurse | General Hospital A | yes |
| 5 | `reception.a@test.com` | receptionist | General Hospital A | yes |
| 6 | `pharmacy.a@test.com` | pharmacist | General Hospital A | yes |
| 7 | `lab.a@test.com` | lab_technician | General Hospital A | yes |
| 8 | `radiology.a@test.com` | radiologist | General Hospital A | yes |
| 9 | `accounts.a@test.com` | accountant | General Hospital A | yes |
| 10 | `records.a@test.com` | records_officer | General Hospital A | yes |
| 11 | `admin.b@test.com` | hospital_admin | City Clinic B | yes |
| 12 | `doctor.b@test.com` | doctor | City Clinic B | yes |

The **super admin is id 1** (seeded first by AdminUserSeeder; DemoSeeder sets its local password
and clears the forced reset).

**Hospital A is USD** (2 decimals, 18% VAT) and **Hospital B is UGX** (0 decimals, no tax). A is
the one every demonstration lands in, so it is the one that has to read naturally to anybody
anywhere; B is a different currency on purpose, because a demo with one money format proves
nothing about the money code. Every price in both seeders is written once in shillings and
converted by `Database\Seeders\DemoMoney` — a figure written raw into a dollar tenant is how a
consultation ends up costing twenty thousand of them.

Both tenants are subscribed to the **uncapped plan**. The cheapest one allows five staff
accounts and the seeder creates ten, so a demo tenant on it was already over its own limit and
`PlanLimit` refused the next patient, bed or staff account somebody tried to create.

## Scenarios these cover

- **Every role's RBAC** — each menu/action is gated; sign in as each to see the enforced surface.
- **Tenancy (A vs B)** — Hospital A's staff never see B's patients/invoices/stock, and vice versa.
- **Configurable currency** — A shows `$` (2 decimals, 18% VAT); B shows `USh` (0 decimals, no tax).
- **Every state a record can be in** — `DemoPopulationSeeder` runs a fixed rota of scenarios, so
  the demo always has a visit waiting for triage *and* one waiting for payment; a lab order on the
  bench *and* one resulted; invoices issued, part paid, settled and void; claims drafted,
  submitted, approved and rejected; appointments kept, missed and cancelled; a patient in a bed
  tonight *and* one discharged last week. No randomness: the same run gives the same hospital, so
  a screenshot or a walkthrough script stays true next week.
- **Everything goes through the services.** Nothing is written straight to a table. A demo built
  by `INSERT` proves the demo builder works; one built through `BillingService`, `VisitService`
  and the rest exercises the code a user does — which is how seeding found that an appointment
  cannot reach Completed without an outcome, and that a card payment debits the card itself.

## Where to sign in (local only)

`/test-login` is the demonstration door: the same form and the same POST as `/admin/login`, with
the seeded **hospital** accounts listed beside it — click a role to fill the form.

**The super admin is not on that list.** It is not a hospital role, so it demonstrates nothing
about the product; what it would do is put a one-click sign-in to the account that can see every
tenant on a page whose whole purpose is to be handed to strangers. It still exists, still has the
demo password locally, and is reachable the ordinary way at `/admin/login`. The rail is built from
`is_admin = false` staff who belong to a hospital, so any future central account is excluded by
construction rather than by being named.

**`/admin/login` lists nothing.** It used to show the panel, which meant a real hospital's
sign-in page was also a list of logins. `/test-login` 404s unless the environment is local/demo
*and* the accounts exist, so a real deployment gives no hint that such a door can exist. Public
pages link to it under "Try the demo", on the same condition.
