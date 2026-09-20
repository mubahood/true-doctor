<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
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
        'visits.manage',   // advance / cancel the pipeline
        'visits.override', // set ANY stage, in either direction, with a reason
        'prescriptions.prescribe', // doctor writes prescriptions + dose items
        'prescriptions.administer', // nurse marks doses given/missed
        // Billing module (Phase 1 Step 11) — seeded with the module it gates.
        'services.manage',  // price-list / service catalogue
        'billing.manage',   // order billable lines, generate invoices, take payments
        'billing.view',     // view invoices / receipts
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
        'finance.view',
        'finance.manage', // create / close / reopen financial years
        // Reports & dashboards (Phase 4 Step 20) — seeded with the module it gates.
        'reports.view',
    ];

    /** @var array<string, '*'|list<string>> */
    public const MATRIX = [
        'super_admin' => '*',
        'hospital_admin' => ['access-admin', 'manage-users', 'manage-settings', 'patients.view', 'patients.create', 'patients.update', 'patients.delete', 'patients.card.manage', 'departments.manage', 'rooms.manage', 'staff.manage', 'schedules.manage', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.create', 'visits.vitals', 'visits.diagnose', 'visits.manage', 'visits.override', 'prescriptions.prescribe', 'prescriptions.administer', 'services.manage', 'billing.manage', 'billing.view', 'pharmacy.view', 'pharmacy.manage', 'pharmacy.dispense', 'lab.view', 'lab.manage', 'lab.order', 'lab.process', 'radiology.view', 'radiology.manage', 'radiology.order', 'radiology.report', 'treatments.view', 'treatments.manage', 'wards.manage', 'ipd.view', 'ipd.manage', 'insurance.view', 'insurance.manage', 'finance.view', 'finance.manage', 'reports.view'],
        'doctor' => ['access-admin', 'patients.view', 'patients.update', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.diagnose', 'visits.manage', 'prescriptions.prescribe', 'billing.manage', 'billing.view', 'lab.view', 'lab.order', 'radiology.view', 'radiology.order', 'treatments.view', 'treatments.manage', 'ipd.view', 'ipd.manage'],
        'nurse' => ['access-admin', 'patients.view', 'patients.update', 'appointments.view', 'visits.view', 'visits.vitals', 'prescriptions.administer', 'treatments.view', 'treatments.manage', 'ipd.view', 'ipd.manage'],
        'receptionist' => ['access-admin', 'patients.view', 'patients.create', 'patients.update', 'patients.card.manage', 'appointments.view', 'appointments.manage', 'visits.view', 'visits.create', 'visits.manage', 'billing.manage', 'billing.view', 'insurance.view'],
        'pharmacist' => ['access-admin', 'patients.view', 'visits.view', 'pharmacy.view', 'pharmacy.manage', 'pharmacy.dispense'],
        'lab_technician' => ['access-admin', 'patients.view', 'visits.view', 'lab.view', 'lab.manage', 'lab.process'],
        'radiologist' => ['access-admin', 'patients.view', 'visits.view', 'radiology.view', 'radiology.manage', 'radiology.report'],
        'accountant' => ['access-admin', 'patients.view', 'patients.card.manage', 'services.manage', 'billing.manage', 'billing.view', 'insurance.view', 'insurance.manage', 'finance.view', 'finance.manage', 'reports.view'],
        'records_officer' => ['access-admin', 'patients.view', 'patients.create', 'patients.update', 'patients.delete'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        foreach (self::MATRIX as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
        }

        $synced = 0;
        foreach (User::all() as $user) {
            $user->syncSpatieRole();
            $synced++;
        }

        $this->command->info('RbacSeeder: '.count(self::MATRIX).' roles + '.count(self::PERMISSIONS)." permissions set, {$synced} users synced.");
    }
}
