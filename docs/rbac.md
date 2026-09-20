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
