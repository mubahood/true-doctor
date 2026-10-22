# Roles & permissions (RBAC)

Built in Phase 0 Step 5 (HMS_PLAN.md §5, §7). Spatie roles/permissions, seeded by
`database/seeders/RbacSeeder.php`.

## Roles

Super Admin (SaaS central, `hospital_id` null) · Hospital Admin · Doctor · Nurse ·
Receptionist · Pharmacist · Lab Technician · Radiologist · Accountant · Records Officer —
`App\Models\User::STAFF_ROLES`, mirrored 1:1 as Spatie role names.

## Permissions seeded today

`access-admin`, `manage-users`, `manage-settings`, `manage-hospitals`, `manage-plans`,
`manage-subscriptions`. Super Admin gets all of them (`'*'`). Hospital Admin gets
`access-admin` + `manage-users` + `manage-settings`. Every other role gets `access-admin`
only — they can sign in and see the shell; nothing module-specific yet.

**Deliberately not seeded**: `module.action` permissions for clinical/billing/pharmacy
actions (`patients.create`, `visits.diagnose`, `billing.refund`,
`pharmacy.dispense`, `stock.adjust`, `reports.financial`, …) that HMS_PLAN.md §5
describes as the eventual naming convention. Those modules don't exist yet (Phase 1+).
Seeding permission strings that nothing checks would be exactly the kind of
unenforceable scaffolding constraint A4 bans — each module seeds and assigns its own
permissions in the same change that ships the routes/Policies enforcing them.

## How a user's role is enforced

`users.role` (string) is the source of truth; `User::syncSpatieRole()` mirrors it into
the Spatie role on every save (`booted()` hook — pure sync, not orchestration, so it's
consistent with constraint A1). Blade gates on `@can('permission-name')` /
`@can('manage-settings')` etc.; route-level gates use the `permission:` middleware
(e.g. `admin/users` requires `manage-users`) or dedicated middleware for coarser checks
(`admin` = any staff role, `super` = Super Admin only).

## Tenancy interaction (why this isn't just "give hospital_admin manage-users")

Giving `hospital_admin` `manage-users` without also scoping `UserController` would have
let one hospital's admin see/edit another hospital's staff — `User` can't carry the
`BelongsToHospital` global scope (super-admin accounts with `hospital_id = null` must
coexist with hospital-scoped ones in the same table). `UserController::scoped()`
enforces this explicitly instead; see `docs/tenancy.md` and
`tests/Feature/StaffManagementIsolationTest.php`.

## Staff onboarding

New staff never get an admin-typed password — `UserController::store()` generates a
random temporary password (`Str::password(16)`), emails it via `WelcomeCredentials`, and
sets `password_change_required = true` (same C14 pattern as `AdminUserSeeder`; see
`docs/auth.md`). A hospital_admin can only create staff for their own hospital
(`hospital_id` auto-assigned from the creating admin); only Super Admin can seed another
`super_admin`.

## Reading the clinical record, and the doctor's view of money (2026-10-17)

`visits.clinical` is the permission to READ the clinical record of a visit: complaints,
diagnosis, notes, prescriptions, the dental chart, lab and imaging results, the
visit report. `visits.view` only lets a person open a visit and see that it happened, its
reason, its stage and (with billing) its bill. The receptionist and the accountant hold
`visits.view` without `visits.clinical`. Policy: `VisitPolicy::viewClinical`.

A doctor holds `billing.manage` without `billing.view`: they put charges on bills and see
the bill of a visit they are the doctor of (`VisitPolicy::viewBill`), not the invoice list,
the cashier's day or the takings. Discounts are `billing.discount`.

## Staff and access: the clinic's own choices

**Settings → Staff and access** (`App\Livewire\Settings\Access`, route `admin.settings.access`).
The matrix above is the default. A clinic changes it with ten plain-language abilities
(`App\Support\Access\Abilities`), each standing for one or more permissions:

| Ability | Permissions | Also given (never taken) |
|---|---|---|
| See clinical notes | `visits.clinical` | |
| Record vitals | `visits.vitals` (needs See clinical notes) | |
| Diagnose and prescribe | `visits.diagnose`, `prescriptions.prescribe` (needs See clinical notes) | |
| Take payments | `billing.manage` | `billing.view` |
| Give discounts | `billing.discount` | |
| Approve refunds | `billing.refund` | |
| Delete visits | `visits.delete` (default: hospital admin only; docs/visits.md, "Deleting a visit") | |
| Change prices | `services.manage` | |
| See reports and money | `billing.view`, `reports.view`, `finance.view` | |
| Manage stock | `pharmacy.manage` | `pharmacy.view` |
| Manage staff and settings | `manage-users`, `manage-settings`, `staff.manage` | |

- Role choices are rows in `role_abilities` (per hospital); one person's extra abilities are
  rows in `user_abilities` (add only, set on the person's account in **User accounts**).
  Both are audited (`AuditTrail::MODELS`). `AccessService` is the only writer.
- `User::hasPermissionTo()` asks `App\Support\Access\PermissionResolver` first, so every
  check (`can`, `@can`, Policies, `permission:` middleware, Spatie's Gate hook, the API,
  `capabilities.permissions`) sees the same answer. Order: an ability's own permission given
  to the role or the person wins; then one the clinic took from the role; then a companion.
- The matrix in the seeder is never edited per clinic, so an update that re-runs the seeder
  keeps every choice. Ticking a box back to the standard deletes the row.
- Safeguards: only a `hospital_admin` may change access; the `hospital_admin` role is not
  editable and is never adjusted; only a hospital admin can make another one; the last active
  hospital admin cannot be switched off, demoted or deleted (`StaffService::assertKeepsAnAdmin`).
- A part of a page a role cannot open says "You do not have access to this. Ask your
  hospital admin." (`x-ui.no-access`), and so do the 403 page and the API's generic 403.
