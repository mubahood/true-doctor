# Insurance (Phase 4, Step 17)

Insurers, per-patient coverage, and a claims lifecycle that settles the invoice when a claim
is paid. Tenant-scoped.

> **There are two arrangements, and they are not the same thing.** A CLAIM (this document)
> settles one invoice for one patient, after the fact. A FLOAT ([cards.md](cards.md)) is money
> the insurer deposits up front, against which member cards run into debt and are cleared in
> bulk; the hospital bills them for the usage afterwards. One insurer row carries both, and
> `InsuranceProviders\Show` is where the float half is worked.

## Files

| Concern | File |
|---|---|
| Tables | `2026_07_19_260000_create_insurance_providers_table.php`, `…_260010_create_patient_insurances_table.php`, `…_260020_create_insurance_claims_table.php` |
| Models | `app/Models/{InsuranceProvider,PatientInsurance,InsuranceClaim}.php` |
| Enum | `app/Enums/ClaimStatus.php` (+ `PaymentMethod::Insurance`) |
| Service | `app/Services/InsuranceService.php` |
| Requests | `app/Http/Requests/{InsuranceProvider,PatientInsurance,InsuranceClaim}Request.php` |
| Policies | `app/Policies/{InsuranceProvider,InsuranceClaim}Policy.php` |
| Controllers | `app/Http/Controllers/Admin/{InsuranceProvider,PatientInsurance,InsuranceClaim}Controller.php` |
| Views | `resources/views/admin/{insurance-providers,insurance-claims}/…` + coverage section on the patient page |

## Providers & coverage

`insurance_providers` is the hospital's insurer directory (name unique per hospital). A patient
gains coverage via `patient_insurances` (member no, coverage %, validity) — added on the patient
page; `PatientInsurance::isValid()` checks active + not-expired.

## Claims lifecycle

`ClaimStatus`: `draft → submitted → approved → paid`, with `rejected` (from submitted/approved)
and `cancelled` (from draft) as terminal outcomes. Only `InsuranceService::transition` moves a
claim (row-locked, guarded). `createClaim` allocates `CLM-<year>-<seq5>` (per-hospital, locking
count, retried on the unique violation).

**Paid claims settle the invoice:** when a claim linked to an invoice reaches `Paid`, the
service records a payment on that invoice through `BillingService::recordPayment`
(`PaymentMethod::Insurance`), capped at the outstanding balance — inside the same transaction, so
the claim's `payment_id` and the invoice ledger never diverge. A partial claim leaves the
invoice `partially_paid`; a rejected/cancelled claim records nothing.

`PaymentMethod::Insurance` was added so an insurance settlement is a first-class payment method
(never the prepaid-card or gateway path).

## RBAC

`insurance.view` (admin, accountant, receptionist) · `insurance.manage` (admin, accountant).

## Tests

`InsuranceClaimTest` (numbering, full lifecycle → insurance payment recorded, partial claim
leaves a balance, rejected records nothing, illegal transition rejected); `InsuranceHttpTest`
(accountant create→paid, receptionist view-not-create, doctor no access, tenant isolation).
