<?php

namespace App\Support;

/**
 * What a visitor should try as each role of the demonstration hospital.
 *
 * Copy only: nothing here grants anything. The app's "Try the demo" door
 * (GET /api/v1/public/demo) joins this catalogue to the demo hospital's REAL
 * staff, so a persona is shown only when somebody holds that role, and what
 * the person can actually open is still decided by the role's permissions.
 * Every mission names a web route that the role's menu contains; a test walks
 * the catalogue against Navigation so none can become a dead end.
 *
 * Icons are names the app already ships (tool/app_icon_names.txt).
 */
final class DemoPersonas
{
    /** @return array<string, array{icon:string, headline:string, can:list<string>, minutes:int, missions:list<array{id:string,title:string,hint:string,route:string}>}> */
    public static function all(): array
    {
        return [
            'doctor' => [
                'icon' => 'fa-heart-pulse',
                'headline' => 'See patients and decide the plan.',
                'can' => ['Open the day\'s queue', 'Record a diagnosis and prescribe', 'Order lab tests and scans'],
                'minutes' => 5,
                'missions' => [
                    self::m('doctor-queue', 'See who is waiting', 'Open the check-in queue and pick the next patient.', 'admin.appointments.queue'),
                    self::m('doctor-visit', 'Open a visit', 'Add a diagnosis and a prescription to a visit in progress.', 'admin.visits.index'),
                    self::m('doctor-lab', 'Check a lab result', 'Open the lab orders and read a finished result.', 'admin.lab-orders.index'),
                ],
            ],
            'nurse' => [
                'icon' => 'fa-user-nurse',
                'headline' => 'Triage, vitals and the ward round.',
                'can' => ['Record vitals at triage', 'Give medicines on the ward', 'See the bed board'],
                'minutes' => 5,
                'missions' => [
                    self::m('nurse-vitals', 'Record vitals', 'Open a visit that is waiting and enter blood pressure and temperature.', 'admin.visits.index'),
                    self::m('nurse-ward', 'Look at the ward', 'Open the bed board and see who is in which bed tonight.', 'admin.admissions.board'),
                    self::m('nurse-offline', 'Offline challenge', 'Switch on airplane mode, record vitals, switch it back on. Nothing is lost.', 'admin.visits.index'),
                ],
            ],
            'receptionist' => [
                'icon' => 'fa-id-badge',
                'headline' => 'Welcome patients and take payment.',
                'can' => ['Register a new patient', 'Check patients in', 'Issue and settle an invoice'],
                'minutes' => 5,
                'missions' => [
                    self::m('reception-register', 'Register a patient', 'Add a new person to the register.', 'admin.patients.index'),
                    self::m('reception-checkin', 'Check someone in', 'Open the check-in queue and mark an arrival.', 'admin.appointments.queue'),
                    self::m('reception-invoice', 'Take a payment', 'Open an invoice and record a payment.', 'admin.invoices.index'),
                    self::m('reception-offline', 'Offline challenge', 'Switch on airplane mode, register a patient, switch it back on. It sends itself.', 'admin.patients.index'),
                ],
            ],
            'pharmacist' => [
                'icon' => 'fa-clipboard-list',
                'headline' => 'Dispense and keep the shelves full.',
                'can' => ['See what to dispense', 'Check stock levels', 'Spot items running low'],
                'minutes' => 4,
                'missions' => [
                    self::m('pharmacy-stock', 'Look at the stock', 'Open the stock list and find an item.', 'admin.stock.index'),
                    self::m('pharmacy-alerts', 'Find what is running low', 'Open the stock alerts.', 'admin.stock.alerts'),
                    self::m('pharmacy-ledger', 'Follow a movement', 'Open the stock ledger and see where items went.', 'admin.stock.movements'),
                ],
            ],
            'lab_technician' => [
                'icon' => 'fa-droplet',
                'headline' => 'Run the bench and report results.',
                'can' => ['See the lab orders waiting', 'Enter and release results', 'Browse the test list'],
                'minutes' => 4,
                'missions' => [
                    self::m('lab-orders', 'Open the bench', 'Open the lab orders and pick one that is waiting.', 'admin.lab-orders.index'),
                    self::m('lab-tests', 'Browse the tests', 'See the tests this lab offers.', 'admin.lab-tests.index'),
                    self::m('lab-patient', 'Find a patient', 'Search the register for a patient\'s name.', 'admin.patients.index'),
                ],
            ],
            'radiologist' => [
                'icon' => 'fa-lungs',
                'headline' => 'Read the scans and write the report.',
                'can' => ['See the imaging orders', 'Write a report', 'Browse the study list'],
                'minutes' => 4,
                'missions' => [
                    self::m('radiology-orders', 'Open the worklist', 'Open the radiology orders and pick one.', 'admin.radiology-orders.index'),
                    self::m('radiology-studies', 'Browse the studies', 'See the studies this department offers.', 'admin.radiology-studies.index'),
                    self::m('radiology-patient', 'Find a patient', 'Search the register for a patient\'s name.', 'admin.patients.index'),
                ],
            ],
            'accountant' => [
                'icon' => 'fa-sack-dollar',
                'headline' => 'Follow the money.',
                'can' => ['See invoices and what is owed', 'Read the books', 'Run reports'],
                'minutes' => 5,
                'missions' => [
                    self::m('accounts-invoices', 'See who owes', 'Open the invoices and filter to what is unpaid.', 'admin.invoices.index'),
                    self::m('accounts-finance', 'Read the finance overview', 'Open the overview of money in and out.', 'admin.finance.index'),
                    self::m('accounts-reports', 'Run a report', 'Open the reports and pick a period.', 'admin.reports.index'),
                    self::m('accounts-claims', 'Check an insurance claim', 'Open the claims and see their status.', 'admin.insurance-claims.index'),
                ],
            ],
            'records_officer' => [
                'icon' => 'fa-file-pen',
                'headline' => 'Keep the patient register right.',
                'can' => ['Register and correct patients', 'Search the register', 'Check offline readiness'],
                'minutes' => 3,
                'missions' => [
                    self::m('records-register', 'Add a patient', 'Register a new person in the patient register.', 'admin.patients.index'),
                    self::m('records-correct', 'Fix a detail', 'Open a patient and correct a phone number or address.', 'admin.patients.index'),
                    self::m('records-offline', 'Check offline readiness', 'See whether this device is ready to work without signal.', 'admin.offline.readiness'),
                ],
            ],
            'hospital_admin' => [
                'icon' => 'fa-people-group',
                'headline' => 'Run the whole hospital.',
                'can' => ['Manage staff accounts', 'Watch money and reports', 'See the wards'],
                'minutes' => 6,
                'missions' => [
                    self::m('admin-users', 'See your team', 'Open the user accounts and their roles.', 'admin.users.index'),
                    self::m('admin-reports', 'Open the reports', 'See how the hospital is doing.', 'admin.reports.index'),
                    self::m('admin-finance', 'Read the finance overview', 'Open the overview of money in and out.', 'admin.finance.index'),
                    self::m('admin-wards', 'Look at the wards', 'Open the bed board.', 'admin.admissions.board'),
                ],
            ],
        ];
    }

    /** @return array{icon:string, headline:string, can:list<string>, minutes:int, missions:list<array{id:string,title:string,hint:string,route:string}>}|null */
    public static function for(string $role): ?array
    {
        return self::all()[$role] ?? null;
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::all());
    }

    /** @return array{id:string,title:string,hint:string,route:string} */
    private static function m(string $id, string $title, string $hint, string $route): array
    {
        return compact('id', 'title', 'hint', 'route');
    }
}
