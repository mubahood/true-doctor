<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * HMS RBAC (HMS_PLAN.md §5): Super Admin (SaaS central) · Hospital Admin ·
 * Doctor · Nurse · Receptionist · Pharmacist · Lab Technician · Radiologist ·
 * Accountant/Cashier · Records Officer.
 *
 * Only permissions that gate something real *today* are seeded here —
 * `module.action` permissions for clinical/billing/pharmacy actions
 * (patients.create, billing.refund, pharmacy.dispense, …) ship alongside
 * their modules in Phase 1+ and get assigned to roles then. Seeding those
 * strings now, with no route or Policy anywhere checking them, would be
 * exactly the kind of unenforceable scaffolding constraint A4 bans — a
 * permission nothing gates is a stub wearing a different hat. Every
 * non-super-admin role gets `access-admin` only for now: they can sign in
 * and see the shell, nothing module-specific yet.
 */
class RbacSeeder extends Seeder
{
    public const PERMISSIONS = [
        'access-admin',
        'manage-users',
        'manage-settings',
        'manage-hospitals',
        'manage-plans',
        'manage-subscriptions',
        // Patients module (Phase 1 Step 7) — seeded with the module it gates.
        'patients.view',
        'patients.create',
        'patients.update',
        'patients.delete',
        'patients.card.manage',  // issue / top-up / charge prepaid cards (money)
        // Staff & org module (Phase 1 Step 8) — seeded with the module it gates.
        'departments.manage',
        'rooms.manage',
        'staff.manage',
        // Scheduling module (Phase 1 Step 9) — seeded with the module it gates.
        'schedules.manage',    // doctor availability templates
        'appointments.view',
        'appointments.manage', // book / reschedule / cancel / advance status
        // Visits module (Phase 1 Step 10) — seeded with the module it gates.
        'visits.view',
        'visits.create',   // open an visit
        'visits.vitals',   // triage / record vitals
        'visits.diagnose', // clinical narrative / diagnosis
        'visits.clinical', // READ the clinical record: notes, diagnoses, vitals, chart, results (docs/rbac.md)
        'visits.report',   // print the visit report without reading the record on screen (a dental clinic's desk)
        'visits.manage',   // advance / cancel the pipeline
        'visits.override', // set ANY stage, in either direction, with a reason
        'prescriptions.prescribe', // doctor writes prescriptions + dose items
        'prescriptions.administer', // nurse marks doses given/missed
        // Billing module (Phase 1 Step 11) — seeded with the module it gates.
        'services.manage',  // price-list / service catalogue
        'billing.manage',   // order billable lines, generate invoices, take payments
        'billing.view',     // view invoices / receipts
        'billing.refund',   // approve credit notes and refunds (docs/refunds.md)
        'billing.discount', // take money off a visit's bill, with a reason
        // Pharmacy / inventory (Phase 2 Step 12) — seeded with the module it gates.
        'pharmacy.view',
        'pharmacy.manage',   // stock items, categories, movements (receive/adjust)
        'pharmacy.dispense', // dispense drugs against a visit (deducts stock + bills)
        // Laboratory (Phase 2 Step 13) — seeded with the module it gates.
        'lab.view',
        'lab.manage',   // test catalogue
        'lab.order',    // order tests on a visit (bills them)
        'lab.process',  // record results + advance status
        // Radiology (Phase 2 Step 13b) — seeded with the module it gates.
        'radiology.view',
        'radiology.manage', // study catalogue
        'radiology.order',  // order studies on a visit (bills them)
        'radiology.report', // write findings/impression + advance status
        // Treatment records (Phase 2 Step 14) — seeded with the module it gates.
        'treatments.view',
        'treatments.manage',
        // Inpatient / IPD (Phase 3 Step 15) — seeded with the module it gates.
        'wards.manage',
        'ipd.view',
        'ipd.manage',   // admit / transfer / discharge
        // Insurance (Phase 4 Step 17) — seeded with the module it gates.
        'insurance.view',
        'insurance.manage', // providers, patient coverage, claims lifecycle
        // Finance / accounting periods (Phase 4 Step 18) — seeded with the module it gates.
        'finance.view',   // the books: overview, transactions, journal, accounts, statements
        'finance.record', // record money in, money out and transfers between books
        'finance.manage', // financial years, the chart of accounts, journals, reversals, finance settings
        // Reports & dashboards (Phase 4 Step 20) — seeded with the module it gates.
        'reports.view',
    ];

    /**
     * The default matrix. A clinic changes it for itself in Settings, Staff
     * and access; those choices live in `role_abilities` and are applied on
     * top of this (App\Support\Access\PermissionResolver), so re-running this
     * seeder on an update never undoes them.
     *
     * Tightened defaults (docs/decisions.md, 2026-10-17): the desk and the
     * cashier see that a visit happened, why, and its bill, but not the
     * clinical record (`visits.clinical`); a doctor puts charges on the bills
     * of their own patients (`billing.manage`) but does not see the clinic's
     * money (`billing.view`) and gives no discounts (`billing.discount`).
     *
     * @var array<string, '*'|list<string>>
     */
    public const MATRIX = [
        'super_admin' => '*',
        'hospital_admin' => ['access-admin', 'manage-users', 'manage-settings', 'patients.view', 'patients.create', 'patients.update', 'patients.delete', 'patients.card.manage', 'departments.manage', 'rooms.manage', 'staff.manage', 'schedules.manage', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.create', 'visits.vitals', 'visits.diagnose', 'visits.clinical', 'visits.report', 'visits.manage', 'visits.override', 'prescriptions.prescribe', 'prescriptions.administer', 'services.manage', 'billing.manage', 'billing.view', 'billing.refund', 'billing.discount', 'pharmacy.view', 'pharmacy.manage', 'pharmacy.dispense', 'lab.view', 'lab.manage', 'lab.order', 'lab.process', 'radiology.view', 'radiology.manage', 'radiology.order', 'radiology.report', 'treatments.view', 'treatments.manage', 'wards.manage', 'ipd.view', 'ipd.manage', 'insurance.view', 'insurance.manage', 'finance.view', 'finance.record', 'finance.manage', 'reports.view'],
        'doctor' => ['access-admin', 'patients.view', 'patients.update', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.vitals', 'visits.diagnose', 'visits.clinical', 'visits.manage', 'prescriptions.prescribe', 'billing.manage', 'lab.view', 'lab.order', 'radiology.view', 'radiology.order', 'treatments.view', 'treatments.manage', 'ipd.view', 'ipd.manage'],
        'nurse' => ['access-admin', 'patients.view', 'patients.update', 'appointments.view', 'visits.view', 'visits.vitals', 'visits.clinical', 'prescriptions.administer', 'treatments.view', 'treatments.manage', 'ipd.view', 'ipd.manage'],
        'receptionist' => ['access-admin', 'patients.view', 'patients.create', 'patients.update', 'patients.card.manage', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.create', 'visits.manage', 'billing.manage', 'billing.view', 'billing.discount', 'insurance.view'],
        'pharmacist' => ['access-admin', 'patients.view', 'visits.view', 'visits.clinical', 'pharmacy.view', 'pharmacy.manage', 'pharmacy.dispense'],
        'lab_technician' => ['access-admin', 'patients.view', 'visits.view', 'visits.clinical', 'lab.view', 'lab.manage', 'lab.process'],
        'radiologist' => ['access-admin', 'patients.view', 'visits.view', 'visits.clinical', 'radiology.view', 'radiology.manage', 'radiology.report'],
        'accountant' => ['access-admin', 'patients.view', 'patients.card.manage', 'services.manage', 'billing.manage', 'billing.view', 'billing.refund', 'billing.discount', 'insurance.view', 'insurance.manage', 'finance.view', 'finance.record', 'finance.manage', 'reports.view'],
        'records_officer' => ['access-admin', 'patients.view', 'patients.create', 'patients.update', 'patients.delete', 'visits.clinical'],
    ];

    public function run(): void
    {
        self::applyMatrix();

        $synced = 0;
        foreach (User::all() as $user) {
            $user->syncSpatieRole();
            $synced++;
        }

        $this->command->info('RbacSeeder: '.count(self::MATRIX).' roles + '.count(self::PERMISSIONS)." permissions set, {$synced} users synced.");
    }

    /** Permissions and the default role matrix, without touching any user. */
    public static function applyMatrix(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        foreach (self::MATRIX as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * For a migration: bring a database that already has roles up to the
     * current matrix. A fresh one (no roles yet) is left to the seeder, so a
     * new or test database is not handed roles it did not ask for.
     */
    public static function applyWhenSeeded(): void
    {
        $roles = (string) config('permission.table_names.roles', 'roles');
        if (! Schema::hasTable($roles) || Role::query()->count() === 0) {
            return;
        }

        self::applyMatrix();
    }
}
