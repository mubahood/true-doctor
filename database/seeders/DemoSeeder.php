<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\Room;
use App\Models\Service as PriceService;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Ward;
use App\Services\PatientService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DEV-ONLY demo/test data. Runs only in the local environment (or with
 * SEED_DEMO=true) — never in production (C14: no default passwords in prod).
 * Seeds one known-password account per HMS role across TWO hospitals so every
 * role and the A-vs-B tenancy boundary can be exercised, plus a light sprinkle
 * of clinical/billing/inventory data so no module is an empty shell.
 *
 * Model events are muted during seeding (DatabaseSeeder uses WithoutModelEvents),
 * so hospital_id / uuid / slug / Spatie roles are all set explicitly here.
 */
class DemoSeeder extends Seeder
{
    /** Shared known password for every demo account (letters + digits, ≥8). */
    public const PASSWORD = 'password1';

    public function run(): void
    {
        // Two ways in. A developer's machine, or a deliberate DEMO_MODE on a
        // real deployment that wants a public demonstration.
        if (! app()->environment(['local', 'demo']) && ! config('demo.enabled')) {
            $this->command->info('DemoSeeder: skipped (not local, and DEMO_MODE is off).');

            return;
        }

        // ── The line that must never move ────────────────────────────────
        // The platform super admin can see EVERY hospital on the platform.
        // It gets the known demo password on a developer's machine and
        // NOWHERE ELSE — not when DEMO_MODE is on, not when somebody sets
        // APP_ENV=demo on a live box, not ever. A published password on that
        // account would hand every tenant's patient records to anybody who
        // read the documentation.
        //
        // This is why the check below is `local` alone and not the same
        // condition as the one above (C14).
        if (app()->environment('local')) {
            $super = User::firstWhere('email', 'admin@gmail.com');
            if ($super !== null) {
                $super->forceFill([
                    'password' => Hash::make(self::PASSWORD),
                    'password_change_required' => false,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ])->save();
                $super->syncSpatieRole();
            }
        }

        // Hospital A is the one every demonstration lands in, so it is the
        // one that has to read naturally to anybody anywhere: USD, two
        // decimals, tax on. B keeps a different currency and no tax, because
        // a demo with one money format proves nothing about the money code.
        $a = $this->hospital('General Hospital A', 'general-hospital-a', 'USD', [
            'currency_code' => 'USD', 'currency_symbol' => '$', 'currency_position' => 'before',
            'decimals' => 2, 'thousands_separator' => ',', 'decimal_separator' => '.',
            'tax_enabled' => true, 'tax_label' => 'VAT', 'tax_rate' => 18,
            'consultation_fee' => 25, 'invoice_prefix' => 'INV',
        ]);
        $b = $this->hospital('City Clinic B', 'city-clinic-b', 'UGX', [
            'currency_code' => 'UGX', 'currency_symbol' => 'USh', 'currency_position' => 'before',
            'decimals' => 0, 'thousands_separator' => ',', 'decimal_separator' => '.',
            'tax_enabled' => false, 'tax_label' => 'Tax', 'tax_rate' => 0,
            'consultation_fee' => 20000, 'invoice_prefix' => 'INV',
        ]);

        // Every HMS role in Hospital A.
        $roles = [
            ['Hospital Admin A', 'admin.a@test.com', 'hospital_admin'],
            ['Dr. Alice (Doctor)', 'doctor.a@test.com', 'doctor'],
            ['Nancy (Nurse)', 'nurse.a@test.com', 'nurse'],
            ['Rita (Receptionist)', 'reception.a@test.com', 'receptionist'],
            ['Paul (Pharmacist)', 'pharmacy.a@test.com', 'pharmacist'],
            ['Larry (Lab tech)', 'lab.a@test.com', 'lab_technician'],
            ['Rhoda (Radiologist)', 'radiology.a@test.com', 'radiologist'],
            ['Alex (Accountant)', 'accounts.a@test.com', 'accountant'],
            ['Rose (Records officer)', 'records.a@test.com', 'records_officer'],
        ];
        $doctorA = null;
        foreach ($roles as [$name, $email, $role]) {
            $u = $this->user($a, $name, $email, $role);
            if ($role === 'doctor') {
                $doctorA = $u;
            }
        }

        // A second tenant for isolation testing.
        $this->user($b, 'Hospital Admin B', 'admin.b@test.com', 'hospital_admin');
        $doctorB = $this->user($b, 'Dr. Bob (Doctor)', 'doctor.b@test.com', 'doctor');

        $this->seedHospitalData($a, $doctorA);
        // B gets the setup it needs to be usable (otherwise its admin is held in
        // the onboarding wizard) but not a second clinical trail — one is enough
        // to demonstrate the modules, and seeding stays fast.
        $this->seedHospitalData($b, $doctorB, withTrail: false);

        $this->command->warn('DemoSeeder: demo accounts seeded — password for all: '.self::PASSWORD);
    }

    private function hospital(string $name, string $slug, string $currency, array $billing): Hospital
    {
        $h = Hospital::firstWhere('slug', $slug);

        // A demo tenant must satisfy the onboarding requirements, otherwise the
        // setup gate holds every demo admin in the wizard.
        $attrs = [
            'name' => $name,
            'currency' => $currency,
            'address' => 'Plot 12, Kampala Road, Kampala',
            'timezone' => 'Africa/Kampala',
            'settings' => [
                'billing' => $billing,
                'contact' => ['phone' => '+256 700 000 000', 'email' => Str::slug($name).'@example.test'],
            ],
            'status' => 'active',
        ];

        if ($h === null) {
            $h = Hospital::create($attrs + ['uuid' => (string) Str::uuid(), 'slug' => $slug]);
        } else {
            $h->update($attrs);
        }

        $this->subscribe($h);

        return $h;
    }

    /**
     * Every demo tenant needs a live subscription: EnsureSubscribed gates all
     * tenant routes, so a demo hospital without one is locked out of its own
     * data. Idempotent — re-running the seeder extends the existing period.
     */
    private function subscribe(Hospital $h): void
    {
        // The UNCAPPED plan, not the cheapest. Starter allows 5 staff and the
        // demo seeds ten, so every demo tenant was already over its own plan
        // limit — the next staff account, patient or bed created through the
        // panel would have been refused by PlanLimit for no reason a
        // demonstrator could act on.
        $plan = \App\Models\Plan::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (\App\Models\Plan $p) => $p->limit('max_staff') === null
                && $p->limit('max_patients') === null
                && $p->limit('max_beds') === null)
            ?? \App\Models\Plan::firstOrCreate(['slug' => 'demo'], [
                'name' => 'Demo', 'price' => '0.00',
                'billing_cycle' => \App\Enums\BillingCycle::Monthly, 'is_active' => true, 'limits' => [],
            ]);

        $subscription = \App\Models\Subscription::withoutGlobalScopes()
            ->where('hospital_id', $h->id)
            ->latest('starts_at')
            ->first();

        $attrs = [
            'plan_id' => $plan->id,
            'status' => \App\Enums\SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => Carbon::now()->addYear(),
            'trial_ends_at' => null,
        ];

        $subscription
            ? $subscription->update($attrs)
            : \App\Models\Subscription::create($attrs + ['hospital_id' => $h->id]);
    }

    private function user(Hospital $h, string $name, string $email, string $role): User
    {
        $u = User::firstWhere('email', $email);
        $attrs = [
            'name' => $name,
            'email' => $email,
            'username' => Str::slug(Str::before($email, '@')),
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'hospital_id' => $h->id,
            'is_admin' => false,
            'is_active' => true,
            'email_verified_at' => now(),
            'password_change_required' => false,
        ];

        $u = $u === null ? User::create($attrs) : tap($u)->update($attrs);
        $u->syncSpatieRole();

        return $u;
    }

    /** A price in this hospital's money. Figures below are written in shillings. */
    private function money(Hospital $h, int|float $shillings): string
    {
        return DemoMoney::in($h->currency, $shillings);
    }

    private function seedHospitalData(Hospital $h, ?User $doctor, bool $withTrail = true): void
    {
        $dept = $this->firstOrMake(Department::class, $h, ['name' => 'Outpatient'], ['is_active' => true]);

        // The doctor belongs to a department, through the staff profile that
        // says so. Without it the demo has doctors and departments and nothing
        // tying them together — which is exactly the state that made choosing a
        // department empty the doctor picker (docs/staff-org.md).
        if ($doctor !== null) {
            $this->firstOrMake(
                \App\Models\StaffProfile::class,
                $h,
                ['user_id' => $doctor->id],
                ['department_id' => $dept->id, 'job_title' => 'Medical Officer', 'is_active' => true],
            );
        }
        $this->firstOrMake(Room::class, $h, ['name' => 'Consultation 1'], ['department_id' => $dept->id, 'type' => 'consultation', 'status' => 'available', 'capacity' => 1]);
        $this->firstOrMake(Room::class, $h, ['name' => 'Consultation 2'], ['department_id' => $dept->id, 'type' => 'consultation', 'status' => 'available', 'capacity' => 1]);

        // Ward + beds.
        $ward = $this->firstOrMake(Ward::class, $h, ['name' => 'General Ward'], [
            'is_active' => true, 'default_daily_charge' => $this->money($h, 30000),
        ]);
        foreach (['Bed 1', 'Bed 2', 'Bed 3'] as $bedName) {
            $this->firstOrMake(Bed::class, $h, ['ward_id' => $ward->id, 'name' => $bedName], ['daily_charge' => $this->money($h, 30000), 'status' => 'available', 'is_active' => true]);
        }

        // Price list.
        foreach ([['General consultation', 20000], ['Wound dressing', 15000], ['Injection (administration)', 5000]] as [$n, $p]) {
            $this->firstOrMake(PriceService::class, $h, ['name' => $n], ['price' => $this->money($h, $p), 'tax_exempt' => false, 'is_active' => true]);
        }

        // Lab + radiology catalogues.
        $this->firstOrMake(LabTest::class, $h, ['name' => 'Full Blood Count'], ['specimen' => 'blood', 'unit' => 'x10^9/L', 'reference_range' => '4.0-11.0', 'price' => $this->money($h, 25000), 'is_active' => true]);
        $this->firstOrMake(LabTest::class, $h, ['name' => 'Malaria RDT'], ['specimen' => 'blood', 'price' => $this->money($h, 10000), 'is_active' => true]);
        $this->firstOrMake(RadiologyStudy::class, $h, ['name' => 'Chest X-ray'], ['modality' => 'X-ray', 'body_part' => 'Chest', 'price' => $this->money($h, 40000), 'is_active' => true]);

        // Stock (with an opening-stock movement so the ledger is consistent).
        $cat = $this->firstOrMake(StockCategory::class, $h, ['name' => 'Drugs'], ['unit' => 'tablets', 'is_active' => true]);
        $this->stockItem($h, $cat, 'Paracetamol 500mg', '500', $this->money($h, 100), $this->money($h, 250));
        $this->stockItem($h, $cat, 'Amoxicillin 250mg', '300', $this->money($h, 300), $this->money($h, 600));

        // Patients (real generated numbers via the service's pure number builder).
        $numbers = app(PatientService::class);
        $people = [['John', 'Doe', 'male'], ['Jane', 'Smith', 'female'], ['Peter', 'Okello', 'male']];
        foreach ($people as $i => [$first, $last, $sex]) {
            $no = $numbers->makeNumber($i + 1);
            if (Patient::withoutGlobalScopes()->where('hospital_id', $h->id)->where('patient_no', $no)->exists()) {
                continue;
            }
            Patient::create([
                'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'patient_no' => $no,
                'first_name' => $first, 'last_name' => $last, 'sex' => $sex,
                'phone_1' => '07'.rand(10000000, 99999999), 'status' => 'active', 'consent_given' => true,
            ]);
        }

        // A doctor availability window (Mon–Fri 09:00–17:00).
        if ($doctor !== null) {
            foreach ([1, 2, 3, 4, 5] as $weekday) {
                \App\Models\DoctorSchedule::firstOrCreate(
                    ['hospital_id' => $h->id, 'user_id' => $doctor->id, 'weekday' => $weekday, 'start_time' => '09:00:00'],
                    ['end_time' => '17:00:00', 'slot_minutes' => 30, 'is_active' => true, 'room_id' => null],
                );
            }
        }

        // A clinical + billing trail so no screen is an empty shell. Runs last:
        // booking an appointment needs the availability windows above.
        if ($withTrail) {
            $this->seedClinicalTrail($h, $doctor);
        }
    }

    /**
     * One realistic journey per demo hospital so every screen has something to
     * show: an appointment, an visit with vitals/diagnosis, charges, lab and
     * radiology orders, a dispensation, a prescription, an invoice with a part
     * payment, an admission occupying a bed, an insurance claim, a prepaid card
     * and the current financial year. Idempotent: it no-ops once a visit
     * exists for this hospital.
     */
    private function seedClinicalTrail(Hospital $h, ?User $doctor): void
    {
        if ($doctor === null || \App\Models\Visit::withoutGlobalScopes()->where('hospital_id', $h->id)->exists()) {
            return;
        }

        // The services below resolve the tenant from the request context, and
        // they rely on model events (BelongsToHospital fills hospital_id on
        // create) which DatabaseSeeder mutes — restore them for this block.
        $previous = app(\App\Support\CurrentHospital::class)->id();
        app(\App\Support\CurrentHospital::class)->set($h->id);
        $dispatcher = \Illuminate\Database\Eloquent\Model::getEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));

        try {
            $patients = Patient::withoutGlobalScopes()->where('hospital_id', $h->id)->orderBy('id')->take(3)->get();
            if ($patients->count() < 2) {
                return;
            }
            [$first, $second] = [$patients[0], $patients[1]];
            $dept = Department::withoutGlobalScopes()->where('hospital_id', $h->id)->first();

            // ── Appointment on the doctor's next availability window ──────
            $slot = Carbon::now()->startOfDay()->addDay();
            while (! in_array($slot->dayOfWeek, [1, 2, 3, 4, 5], true)) {
                $slot->addDay();
            }
            app(\App\Services\AppointmentService::class)->book([
                'patient_id' => $first->id, 'doctor_user_id' => $doctor->id, 'department_id' => $dept?->id,
                'scheduled_at' => $slot->copy()->setTime(9, 0)->toDateTimeString(),
                'duration_minutes' => 30, 'source' => 'phone', 'reason' => 'Follow-up review',
            ], $doctor->id);

            // ── Visit with vitals + clinical notes ────────────────────
            $visits = app(\App\Services\VisitService::class);
            $visit = $visits->open([
                'patient_id' => $first->id, 'doctor_user_id' => $doctor->id, 'department_id' => $dept?->id,
                'reason' => 'Fever and headache', 'complaints' => 'Fever for three days, headache, joint pain.',
            ], $doctor->id);
            $visits->recordVitals($visit, [
                'temperature' => 38.4, 'blood_pressure' => '118/76', 'weight' => 68.5,
                'height' => 172, 'pulse' => 92, 'spo2' => 97, 'respiratory_rate' => 18,
            ]);
            $visits->updateClinical($visit->fresh(), [
                'doctor_user_id' => $doctor->id,
                'diagnosis' => 'Uncomplicated malaria (confirmed by RDT).',
                'doctor_remarks' => 'Start antimalarials; review in 3 days if fever persists.',
            ]);

            // ── Charges, orders, dispensing, prescription ─────────────────
            $billing = app(\App\Services\BillingService::class);
            $visit = $visit->fresh();
            if ($service = PriceService::withoutGlobalScopes()->where('hospital_id', $h->id)->where('name', 'General consultation')->first()) {
                $billing->orderService($visit, $service->id, 1, $doctor->id);
            }
            if ($test = LabTest::withoutGlobalScopes()->where('hospital_id', $h->id)->first()) {
                app(\App\Services\LabService::class)->order($visit, [$test->id], 'Rule out malaria.', $doctor->id);
            }
            if ($study = RadiologyStudy::withoutGlobalScopes()->where('hospital_id', $h->id)->first()) {
                app(\App\Services\RadiologyService::class)->order($visit, [$study->id], 'Persistent cough.', $doctor->id);
            }
            if ($drug = StockItem::withoutGlobalScopes()->where('hospital_id', $h->id)->first()) {
                app(\App\Services\DispensationService::class)->dispense(
                    $visit, [['stock_item_id' => $drug->id, 'quantity' => '6']], 'Take after meals.', $doctor->id
                );
            }
            app(\App\Services\PrescriptionService::class)->prescribe($visit, [[
                'drug_name' => 'Artemether/Lumefantrine 20/120', 'dosage' => '4 tablets',
                'slots' => ['morning', 'evening'], 'days' => 3,
                'start_date' => Carbon::now()->toDateString(), 'instructions' => 'After food.',
            ]], 'Complete the full course.', $doctor->id);

            // ── Invoice + part payment ───────────────────────────────────
            $invoice = $billing->generateInvoice($visit->fresh(), '0.00', $doctor->id);
            $part = bcdiv((string) $invoice->total, '2', 2);
            if (bccomp($part, '0.00', 2) > 0) {
                $billing->recordPayment($invoice, \App\Enums\PaymentMethod::Cash, $part, [], $doctor->id);
            }

            // ── A prepaid card, shared with the family ───────────────────
            // `credit_limit` was a typo for `max_credit`, so this card has been
            // shipping with credit allowed and a limit of nothing.
            $cards = app(\App\Services\CardService::class);
            $card = $cards->issue($second, ['accepts_credit' => true, 'max_credit' => $this->money($h, 50000)], $doctor->id);
            $cards->credit($card, $this->money($h, 25000), 'Demo top-up', $doctor->id);
            $cards->addHolder($card, $first, \App\Enums\CardHolderRelationship::Spouse, $doctor->id);
            $cards->debit($card, $this->money($h, 4000), 'Consultation', $doctor->id, ['for' => $first->id]);

            // ── Inpatient admission occupying a bed ──────────────────────
            $bed = Bed::withoutGlobalScopes()->where('hospital_id', $h->id)->where('status', 'available')->first();
            if ($bed !== null) {
                app(\App\Services\AdmissionService::class)->admit($second, $bed, [
                    'admitted_at' => Carbon::now()->subDay()->toDateTimeString(),
                    'admitting_doctor_id' => $doctor->id, 'reason' => 'Severe dehydration — IV fluids.',
                    'title' => 'Admit for IV treatment',
                ], $doctor->id);
            }

            // ── Insurance provider + claim on the invoice ────────────────
            $provider = $this->firstOrMake(\App\Models\InsuranceProvider::class, $h, ['name' => 'Jubilee Health'], [
                'is_active' => true, 'default_credit_limit' => $this->money($h, 200000),
            ]);
            app(\App\Services\InsuranceService::class)->createClaim([
                'patient_id' => $first->id, 'insurance_provider_id' => $provider->id,
                'invoice_id' => $invoice->id, 'amount' => $part, 'notes' => 'Demo claim for the balance.',
            ], $doctor->id);

            // ── The other arrangement: a member card against a float ─────
            // A claim settles one invoice; a float funds a card that is meant
            // to run negative and is cleared in bulk (docs/cards.md). The demo
            // shows both, and leaves the member in debt so the Clear button on
            // the insurer's page has something to do.
            $ledger = app(\App\Services\InsuranceLedgerService::class);
            $ledger->deposit($provider, $this->money($h, 500000), 'RTGS-DEMO-001', 'Opening float', $doctor->id);

            $memberCard = $cards->issue($first, [
                'insurance_provider_id' => $provider->id,
                'member_no' => 'JH-'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT),
            ], $doctor->id);
            $cards->debit($memberCard, $this->money($h, 65000), 'Outpatient visit', $doctor->id);

            // ── Current financial year ───────────────────────────────────
            if (! \App\Models\FinancialYear::withoutGlobalScopes()->where('hospital_id', $h->id)->exists()) {
                app(\App\Services\FinancialYearService::class)->create([
                    'name' => 'FY '.Carbon::now()->year,
                    'starts_on' => Carbon::now()->startOfYear()->toDateString(),
                    'ends_on' => Carbon::now()->endOfYear()->toDateString(),
                ]);
            }

            // ── A treatment record with no photos ────────────────────────
            app(\App\Services\TreatmentService::class)->create($first, [
                'procedure' => 'Wound dressing', 'notes' => 'Clean dressing applied; no signs of infection.',
                'performed_at' => Carbon::now()->subDays(2)->toDateTimeString(),
            ], [], $doctor->id);
        } finally {
            // Restore whatever was there, rather than unsetting.
            // `unsetEventDispatcher()` removes it GLOBALLY — so anything running
            // afterwards in the same process silently loses model events, which
            // in this codebase means uuids, slugs and (worse) the hospital_id
            // that BelongsToHospital fills on create. Fine when the process ends
            // straight after; a trap in a test, a queue worker, or a command that
            // seeds and then keeps going.
            $dispatcher
                ? \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher)
                : \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
            app(\App\Support\CurrentHospital::class)->set($previous);
        }
    }

    /** firstOrCreate with an explicit hospital_id (model events are muted here). */
    private function firstOrMake(string $model, Hospital $h, array $lookup, array $extra)
    {
        $existing = $model::withoutGlobalScopes()->where($lookup + ['hospital_id' => $h->id])->first();
        if ($existing !== null) {
            return $existing;
        }

        return $model::create($lookup + $extra + ['hospital_id' => $h->id]);
    }

    private function stockItem(Hospital $h, StockCategory $cat, string $name, string $qty, string $cost, string $sale): void
    {
        if (StockItem::withoutGlobalScopes()->where('hospital_id', $h->id)->where('name', $name)->exists()) {
            return;
        }

        $value = bcmul($qty, $cost, 2);
        $item = StockItem::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'stock_category_id' => $cat->id,
            'name' => $name, 'unit' => 'tablets', 'original_quantity' => $qty, 'current_quantity' => $qty,
            'cost_price' => $cost, 'sale_price' => $sale, 'current_stock_value' => $value,
            'reorder_level' => '50', 'is_active' => true,
        ]);
        StockMovement::create([
            'hospital_id' => $h->id, 'stock_item_id' => $item->id, 'reason' => 'opening_stock',
            'quantity' => $qty, 'balance_after' => $qty, 'unit_cost' => $cost, 'created_at' => Carbon::now(),
        ]);
    }
}
