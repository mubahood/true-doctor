<?php

namespace Database\Seeders;

use App\Enums\AdmissionStatus;
use App\Enums\AppointmentStatus;
use App\Enums\CardHolderRelationship;
use App\Enums\ClaimStatus;
use App\Enums\LabOrderStatus;
use App\Enums\PatientStatus;
use App\Enums\PaymentMethod;
use App\Enums\RadiologyOrderStatus;
use App\Enums\ResultFlag;
use App\Models\Bed;
use App\Models\Department;
use App\Models\District;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\Room;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\AppointmentService;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\DispensationService;
use App\Services\InsuranceLedgerService;
use App\Services\InsuranceService;
use App\Services\LabService;
use App\Services\PatientService;
use App\Services\PrescriptionService;
use App\Services\RadiologyService;
use App\Services\TreatmentService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A demonstration hospital with a quarter of work behind it.
 *
 * DemoSeeder gives every role an account and every module one row, which is
 * enough to prove nothing is broken and not enough to show anybody what the
 * system is for. A screen with one patient on it cannot demonstrate searching,
 * paging, filtering, an ageing debt or a busy ward, and a reporting page over
 * a single day is a row of zeroes.
 *
 * So this seeds a real workload: sixty-odd people on the register and as many
 * episodes of care spread across the last three months, deliberately arranged
 * so that every state a record can be in exists somewhere. A visit waiting for
 * triage AND one waiting for payment. A lab order still on the bench AND one
 * resulted. An invoice unpaid, part paid, settled and void. A patient in a bed
 * tonight and one who went home last week.
 *
 * Three rules it keeps:
 *
 *  1. EVERYTHING GOES THROUGH THE SERVICES. Nothing is written straight to a
 *     table. A demo built by INSERT proves the demo builder works; one built
 *     through BillingService and VisitService exercises the same code a user
 *     does, so a broken rule fails here rather than in front of somebody.
 *  2. IT IS REPRODUCIBLE. No randomness. The same run gives the same hospital,
 *     so a screenshot or a walkthrough script stays true tomorrow.
 *  3. IT IS IDEMPOTENT. Running it twice tops up to the target rather than
 *     doubling the hospital.
 *
 * Dev-only, like DemoSeeder: it seeds known-password accounts' data and has no
 * business anywhere near a real hospital's database.
 */
class DemoPopulationSeeder extends Seeder
{
    /** How far back the history reaches. */
    private const DAYS_OF_HISTORY = 96;

    private Hospital $hospital;

    private User $doctor;

    private ?User $nurse = null;

    private ?User $cashier = null;

    private ?User $desk = null;

    private ?User $pharmacist = null;

    private ?User $technician = null;

    private ?User $radiographer = null;

    public function run(): void
    {
        if (! app()->environment(['local', 'demo']) && ! config('demo.enabled')) {
            $this->command->info('DemoPopulationSeeder: skipped (not local, and DEMO_MODE is off).');

            return;
        }

        $hospital = Hospital::withoutGlobalScopes()->firstWhere('slug', 'general-hospital-a');

        if ($hospital === null) {
            $this->command->warn('DemoPopulationSeeder: run DemoSeeder first — no demo hospital found.');

            return;
        }

        $doctor = User::withoutGlobalScopes()->firstWhere('email', 'doctor.a@test.com');

        if ($doctor === null) {
            $this->command->warn('DemoPopulationSeeder: run DemoSeeder first — no demo doctor found.');

            return;
        }

        $this->hospital = $hospital;
        $this->doctor = $doctor;
        $this->nurse = $this->colleague('nurse.a@test.com');
        $this->cashier = $this->colleague('accounts.a@test.com');
        $this->desk = $this->colleague('reception.a@test.com');
        $this->pharmacist = $this->colleague('pharmacy.a@test.com');
        $this->technician = $this->colleague('lab.a@test.com');
        $this->radiographer = $this->colleague('radiology.a@test.com');

        $this->inTenant(function () {
            $this->catalogue();
            $roster = $this->register();
            $this->history($roster);
            $this->appointments($roster);
            $this->standingArrangements($roster);
        });

        $this->command->info(sprintf(
            'DemoPopulationSeeder: %d patients, %d visits, %d appointments in %s.',
            Patient::withoutGlobalScopes()->where('hospital_id', $this->hospital->id)->count(),
            Visit::withoutGlobalScopes()->where('hospital_id', $this->hospital->id)->count(),
            \App\Models\Appointment::withoutGlobalScopes()->where('hospital_id', $this->hospital->id)->count(),
            $this->hospital->name,
        ));
    }

    /**
     * Run a block as this hospital, with the model events the services need.
     *
     * DatabaseSeeder mutes model events (WithoutModelEvents), and BelongsToHospital
     * fills hospital_id on create through one — so without this every row
     * written here would land with no tenant at all.
     */
    private function inTenant(\Closure $work): void
    {
        $previous = app(CurrentHospital::class)->id();
        $dispatcher = Model::getEventDispatcher();
        app(CurrentHospital::class)->set($this->hospital->id);
        Model::setEventDispatcher(app('events'));

        try {
            $work();
        } finally {
            // Whatever happened, do not leave the process pinned to a moment
            // in the past, or to somebody else's hospital, or without model
            // events.
            Carbon::setTestNow();
            // Restore whatever was there, rather than unsetting.
            // `unsetEventDispatcher()` removes it GLOBALLY — so anything running
            // afterwards in the same process silently loses model events, which
            // in this codebase means uuids, slugs and (worse) the hospital_id
            // that BelongsToHospital fills on create. Fine when the process ends
            // straight after; a trap in a test, a queue worker, or a command that
            // seeds and then keeps going.
            $dispatcher ? Model::setEventDispatcher($dispatcher) : Model::unsetEventDispatcher();
            app(CurrentHospital::class)->set($previous);
        }
    }

    /**
     * Who to record as having done a piece of work.
     *
     * Every role has a demo account, but a database seeded before one of them
     * existed will not have all of them — and a null `created_by` on sixty
     * records makes every "raised by" column on every screen read "—". The
     * doctor stands in, because somebody always did the work.
     */
    private function actor(?User $user): int
    {
        return $user instanceof User ? $user->id : $this->doctor->id;
    }

    /** A price, in this hospital's money. Every figure below is in shillings. */
    private function money(int|float $shillings): string
    {
        return DemoMoney::in($this->hospital->currency, $shillings);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  The catalogue — what the hospital offers
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Departments, rooms, wards, beds, price list, tests, studies and stock.
     *
     * Deep enough that a picker is worth searching and a ward board has
     * something to show. DemoSeeder's three beds cannot demonstrate occupancy.
     */
    private function catalogue(): void
    {
        $departments = [
            'Outpatient' => 'General consultation and triage',
            'Maternity' => 'Antenatal, delivery and postnatal care',
            'Paediatrics' => 'Care for patients under 18',
            'Surgery' => 'Elective and emergency operations',
            'Dental' => 'Oral health and extractions',
            'Eye clinic' => 'Refraction, cataract and glaucoma care',
        ];

        foreach ($departments as $name => $description) {
            $this->make(Department::class, ['name' => $name], ['description' => $description, 'is_active' => true]);
        }

        $outpatient = Department::where('name', 'Outpatient')->first();

        // The doctor has to belong to a department, or choosing one empties
        // the doctor picker (docs/staff-org.md).
        foreach (User::withoutGlobalScopes()->where('hospital_id', $this->hospital->id)->whereIn('role', ['doctor', 'nurse'])->get() as $clinician) {
            $this->make(StaffProfile::class, ['user_id' => $clinician->id], [
                'department_id' => $outpatient?->id,
                'job_title' => $clinician->role === 'doctor' ? 'Medical Officer' : 'Registered Nurse',
                'is_active' => true,
            ]);
        }

        foreach ([
            ['Consultation 1', 'consultation'], ['Consultation 2', 'consultation'],
            ['Consultation 3', 'consultation'], ['Triage bay', 'consultation'],
            ['Treatment room', 'other'], ['Minor theatre', 'theatre'],
            ['Laboratory', 'lab'], ['Imaging room', 'radiology'], ['Pharmacy counter', 'pharmacy'],
        ] as [$name, $type]) {
            $this->make(Room::class, ['name' => $name], [
                'department_id' => $outpatient?->id, 'type' => $type, 'status' => 'available', 'capacity' => 1,
            ]);
        }

        // Wards, with a nightly rate each — the figure the per-night bed
        // billing accrues against every morning.
        $wards = [
            'General Ward' => [12, 30000],
            'Maternity Ward' => [8, 45000],
            'Paediatric Ward' => [8, 35000],
            'Private Wing' => [5, 120000],
        ];

        foreach ($wards as $wardName => [$count, $nightly]) {
            $rate = $this->money($nightly);

            $ward = $this->sync(Ward::class, ['name' => $wardName], [
                'is_active' => true,
                'default_daily_charge' => $rate,
            ]);

            $initials = Str::of($wardName)->explode(' ')->map(fn ($w) => Str::substr($w, 0, 1))->implode('');

            for ($n = 1; $n <= $count; $n++) {
                $this->make(Bed::class, ['ward_id' => $ward->id, 'name' => sprintf('%s-%02d', $initials, $n)], [
                    'daily_charge' => $rate, 'status' => 'available', 'is_active' => true,
                ]);
            }

            // Beds this seeder did not create — the three DemoSeeder leaves in
            // the General Ward — are brought to the ward rate too. Through the
            // ward service, which is the same path the reprice checkbox on the
            // ward screen takes, so the activity log reads the same either way.
            app(\App\Services\WardService::class)->applyNightlyChargeToBeds($ward->fresh(), $rate);
        }

        // The price list. Everything billed anywhere in this demo is here.
        foreach ([
            ['General consultation', 20000], ['Specialist consultation', 50000],
            ['Antenatal review', 25000], ['Wound dressing', 15000],
            ['Injection (administration)', 5000], ['Nebulisation', 18000],
            ['Suturing (minor)', 40000], ['Incision and drainage', 60000],
            ['Tooth extraction', 45000], ['Dental scaling', 55000],
            ['Eye refraction', 20000], ['Circumcision', 150000],
            ['Normal delivery', 250000], ['Caesarean section', 800000],
            ['Physiotherapy session', 30000], ['Family planning counselling', 10000],
            ['Immunisation (routine)', 0], ['Medical examination certificate', 35000],
        ] as [$name, $price]) {
            $this->sync(Service::class, ['name' => $name], [
                'price' => $this->money($price), 'tax_exempt' => $price === 0, 'is_active' => true,
            ]);
        }

        foreach ([
            ['Full Blood Count', 'blood', 'x10^9/L', '4.0-11.0', 25000],
            ['Malaria RDT', 'blood', null, 'Negative', 10000],
            ['Blood Slide for Malaria', 'blood', 'parasites/µL', 'Not seen', 12000],
            ['Random Blood Sugar', 'blood', 'mmol/L', '3.9-7.8', 8000],
            ['HbA1c', 'blood', '%', '4.0-5.6', 60000],
            ['Liver Function Tests', 'blood', 'U/L', '7-56', 70000],
            ['Renal Function Tests', 'blood', 'µmol/L', '60-110', 65000],
            ['Lipid Profile', 'blood', 'mmol/L', '<5.2', 55000],
            ['Urinalysis', 'urine', null, 'Normal', 15000],
            ['Stool Analysis', 'stool', null, 'No ova or cysts', 15000],
            ['HIV Screening', 'blood', null, 'Non-reactive', 0],
            ['Hepatitis B Surface Antigen', 'blood', null, 'Negative', 30000],
            ['Pregnancy Test (Beta-hCG)', 'urine', null, 'Negative', 12000],
            ['Widal Test', 'blood', 'titre', '<1:80', 20000],
            ['Sputum for AFB', 'sputum', null, 'No AFB seen', 18000],
        ] as [$name, $specimen, $unit, $range, $price]) {
            $this->sync(LabTest::class, ['name' => $name], [
                'specimen' => $specimen, 'unit' => $unit, 'reference_range' => $range,
                'price' => $this->money($price), 'is_active' => true,
            ]);
        }

        foreach ([
            ['Chest X-ray', 'X-ray', 'Chest', 40000],
            ['Abdominal X-ray', 'X-ray', 'Abdomen', 45000],
            ['Limb X-ray', 'X-ray', 'Limb', 35000],
            ['Abdominal Ultrasound', 'Ultrasound', 'Abdomen', 60000],
            ['Obstetric Ultrasound', 'Ultrasound', 'Pelvis', 55000],
            ['Pelvic Ultrasound', 'Ultrasound', 'Pelvis', 55000],
            ['Echocardiogram', 'Ultrasound', 'Heart', 120000],
            ['CT Head (non-contrast)', 'CT', 'Head', 350000],
        ] as [$name, $modality, $part, $price]) {
            $this->sync(RadiologyStudy::class, ['name' => $name], [
                'modality' => $modality, 'body_part' => $part,
                'price' => $this->money($price), 'is_active' => true,
            ]);
        }

        // Stock: enough breadth that the pharmacy screen is worth filtering,
        // and two items deliberately below reorder level so the alerts page
        // has something to alert about.
        $drugs = $this->make(StockCategory::class, ['name' => 'Drugs'], ['unit' => 'tablets', 'is_active' => true]);
        $supplies = $this->make(StockCategory::class, ['name' => 'Medical supplies'], ['unit' => 'pieces', 'is_active' => true]);

        foreach ([
            [$drugs, 'Paracetamol 500mg', 'tablets', '2400', 100, 250, '200', null],
            [$drugs, 'Amoxicillin 250mg', 'capsules', '1200', 300, 600, '200', null],
            [$drugs, 'Artemether/Lumefantrine 20/120', 'tablets', '640', 900, 1800, '120', '+14 months'],
            [$drugs, 'Metronidazole 400mg', 'tablets', '900', 150, 350, '150', null],
            [$drugs, 'Ibuprofen 400mg', 'tablets', '1500', 120, 300, '150', null],
            [$drugs, 'Ceftriaxone 1g injection', 'vials', '180', 4500, 9000, '40', '+4 months'],
            [$drugs, 'Omeprazole 20mg', 'capsules', '700', 250, 600, '100', null],
            [$drugs, 'Amlodipine 5mg', 'tablets', '860', 180, 450, '100', null],
            [$drugs, 'Metformin 500mg', 'tablets', '1100', 160, 400, '120', null],
            [$drugs, 'Salbutamol inhaler', 'inhalers', '26', 9000, 18000, '30', '+7 months'],
            [$drugs, 'Oral Rehydration Salts', 'sachets', '480', 900, 1800, '80', null],
            [$drugs, 'Ferrous sulphate + folic acid', 'tablets', '1300', 80, 200, '150', null],
            [$supplies, 'Examination gloves (medium)', 'pairs', '1900', 400, 900, '300', null],
            [$supplies, 'Surgical gauze 10x10', 'packs', '210', 1500, 3200, '60', null],
            [$supplies, 'Giving set (IV)', 'pieces', '140', 3000, 6000, '50', null],
            [$supplies, 'Cannula 20G', 'pieces', '95', 1800, 3600, '100', null],
            [$supplies, 'Syringe 5ml', 'pieces', '620', 500, 1200, '200', null],
            [$supplies, 'Rapid test cassette (malaria)', 'pieces', '38', 2500, 5000, '60', '+3 months'],
        ] as [$category, $name, $unit, $quantity, $cost, $sale, $reorder, $expiry]) {
            $this->stock($category, $name, $unit, $quantity, $cost, $sale, $reorder, $expiry);
        }

        $this->retireLegacyCatalogue();

        $this->sync(InsuranceProvider::class, ['name' => 'Jubilee Health'], [
            'is_active' => true, 'default_credit_limit' => $this->money(200000), 'code' => 'JH',
        ]);
        $this->sync(InsuranceProvider::class, ['name' => 'AAR Health Services'], [
            'is_active' => true, 'default_credit_limit' => $this->money(300000), 'code' => 'AAR',
        ]);
        $this->sync(InsuranceProvider::class, ['name' => 'National Social Health Scheme'], [
            'is_active' => true, 'default_credit_limit' => $this->money(150000), 'code' => 'NSHS',
        ]);
    }

    /**
     * Take the previous seeder's price rows off the list.
     *
     * An earlier DemoSeeder wrote "Consultation" and "Injection" in raw
     * shillings. This one writes "General consultation" and "Injection
     * (administration)" in the hospital's own money, so on a dev database
     * seeded before today both sit on the price list together — one of them
     * priced at twenty thousand dollars.
     *
     * Deactivated rather than deleted. An inactive service leaves every
     * picker, and stays on every invoice that already charged it — which is
     * the difference between tidying a price list and rewriting history.
     */
    private function retireLegacyCatalogue(): void
    {
        $replaced = [
            'Consultation' => 'General consultation',
            'Visit' => 'General consultation',
            'Injection' => 'Injection (administration)',
        ];

        foreach ($replaced as $was => $now) {
            $old = Service::where('name', $was)->first();

            if ($old === null || ! Service::where('name', $now)->exists()) {
                continue;
            }

            $old->forceFill(['is_active' => false])->save();
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  The register
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Put the roster on the register, through the service that numbers them.
     *
     * Idempotency is by NAME, not by count. A dev database that already has a
     * hundred patients in it from an afternoon of clicking about is the
     * normal case, and a count check would have decided the roster was
     * already seeded and skipped all of it.
     *
     * @return list<Patient> the roster, in roster order
     */
    private function register(): array
    {
        $roster = DemoPeople::roster();
        $allergies = DemoPeople::allergies();
        $conditions = DemoPeople::conditions();
        $districts = District::query()->orderBy('id')->pluck('id')->all();
        $patients = app(PatientService::class);
        $registered = [];

        // Registered over the same window as the visits, so the "new patients
        // this month" figures on the dashboard are not all one day.
        $spread = max(1, count($roster));

        foreach ($roster as $i => [$first, $last, $sex, $age, $blood]) {
            $already = Patient::where('first_name', $first)->where('last_name', $last)->first();

            if ($already !== null) {
                $registered[] = $already;

                continue;
            }

            $registeredOn = Carbon::now()
                ->subDays((int) round(self::DAYS_OF_HISTORY - ($i / $spread) * (self::DAYS_OF_HISTORY - 2)))
                ->setTime(8 + ($i % 8), ($i % 4) * 15);

            Carbon::setTestNow($registeredOn);

            $registered[] = $patients->register([
                'first_name' => $first,
                'last_name' => $last,
                'sex' => $sex,
                // A birthday spread through the year so age bands do not all
                // tick over on the same day.
                'dob' => Carbon::now()->subYears($age)->subDays($i * 6 % 360)->toDateString(),
                'phone_1' => '+2567'.str_pad((string) (10000000 + $i * 137911), 8, '0', STR_PAD_LEFT),
                'phone_2' => $i % 5 === 0 ? '+2567'.str_pad((string) (20000000 + $i * 91237), 8, '0', STR_PAD_LEFT) : null,
                'email' => $i % 4 === 0 ? Str::lower($first.'.'.$last).'@example.test' : null,
                'address' => $this->addressFor($i),
                'district_id' => $districts === [] ? null : $districts[$i % count($districts)],
                'blood_type' => $blood,
                'allergies' => $allergies[$i] ?? [],
                'chronic_conditions' => $conditions[$i] ?? [],
                'emergency_contact_name' => $this->kinFor($i, $last),
                'emergency_contact_phone' => '+2567'.str_pad((string) (30000000 + $i * 55411), 8, '0', STR_PAD_LEFT),
                'consent_given' => true,
                'status' => $this->statusFor($i),
                'notes' => $this->noteFor($i),
            ], $this->actor($this->desk));

            Carbon::setTestNow();
        }

        return $registered;
    }

    private function addressFor(int $i): string
    {
        $places = [
            'Plot 14, Nakivubo Road, Kampala', 'Bukoto II, Kampala', 'Nansana West, Wakiso',
            'Kireka Zone B, Kira', 'Main Street, Jinja', 'Nakawa Trading Centre, Kampala',
            'Kisenyi III, Kampala', 'Bweyogerere, Wakiso', 'Layibi Division, Gulu',
            'Kasese Town Council', 'Mbale Industrial Area', 'Ntinda Ministers Village, Kampala',
        ];

        return $places[$i % count($places)];
    }

    private function kinFor(int $i, string $surname): string
    {
        $firsts = ['Robert', 'Immaculate', 'Joseph', 'Rose', 'Michael', 'Annet', 'David', 'Sylvia'];

        return $firsts[$i % count($firsts)].' '.$surname;
    }

    /** Almost everybody is active. A register with no exceptions in it is not one. */
    private function statusFor(int $i): string
    {
        return match (true) {
            $i === 52 => PatientStatus::Deceased->value,
            $i % 19 === 7 => PatientStatus::Inactive->value,
            default => PatientStatus::Active->value,
        };
    }

    private function noteFor(int $i): ?string
    {
        return match (true) {
            $i === 3 => 'Severe penicillin reaction in 2019 — confirm alternative before prescribing.',
            $i === 25 => 'On antihypertensives; brings own BP diary to every review.',
            $i === 38 => 'Diabetic and hypertensive. Needs a longer appointment slot.',
            $i === 43 => 'Epilepsy — carer accompanies to all appointments.',
            $i === 52 => 'Record retained for family history. Do not schedule.',
            default => null,
        };
    }

    /**
     * One of the demo's staff, if DemoSeeder made them.
     *
     * The explicit ?User matters: a query builder's `firstWhere` is typed as
     * returning a model, so every `?->` hung off one reads as redundant even
     * though the row genuinely may not be there. Saying it once, here, makes
     * the nullability true of the code rather than only of the world.
     */
    private function colleague(string $email): ?User
    {
        /** @var User|null $user */
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        return $user;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  The work
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Three months of episodes, one per patient, each stopped at a different
     * point so every state a visit can be in is on the screen somewhere.
     *
     * The rota is fixed rather than random: the demo is the same every time,
     * and every scenario is guaranteed present rather than probably present.
     */
    private function history(array $roster): void
    {
        /** @var list<string> */
        $rota = [
            'registered', 'triaged', 'consulting', 'labs_pending', 'labs_resulted',
            'imaging_pending', 'imaging_reported', 'dispensed', 'prescribed',
            'awaiting_payment', 'part_paid', 'settled', 'insured', 'card_paid',
            'admitted', 'discharged', 'settled', 'labs_resulted', 'consulting',
            'awaiting_payment', 'settled', 'dispensed', 'part_paid', 'triaged',
            'settled', 'imaging_reported', 'voided',
        ];

        $total = max(1, count($roster));

        foreach ($roster as $i => $patient) {
            // Deceased and inactive patients are on the register to be found,
            // not to have fresh work raised against them.
            if ($patient->status !== PatientStatus::Active) {
                continue;
            }

            // Somebody who already has an episode has already been through
            // here. By the record, not by a counter, so a half-finished run
            // resumes where it stopped.
            if (Visit::where('patient_id', $patient->id)->exists()) {
                continue;
            }

            $scenario = $rota[$i % count($rota)];
            $day = Carbon::now()
                ->subDays((int) round(self::DAYS_OF_HISTORY - ($i / $total) * (self::DAYS_OF_HISTORY - 1)))
                ->setTime(8 + ($i % 9), ($i % 4) * 15);

            try {
                Carbon::setTestNow($day);
                $this->episode($patient, $scenario, $i);
            } catch (\Throwable $e) {
                // One awkward episode must not cost the other sixty. Say which
                // and carry on — a silent skip is how a demo quietly loses a
                // whole module.
                $this->command->warn(sprintf(
                    'DemoPopulationSeeder: %s for %s skipped — %s',
                    $scenario, $patient->patient_no, $e->getMessage(),
                ));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    /** One patient's episode of care, carried as far as its scenario says. */
    private function episode(Patient $patient, string $scenario, int $i): void
    {
        $visits = app(VisitService::class);
        $billing = app(BillingService::class);
        $by = $this->doctor->id;

        $presentation = $this->presentation($i);
        $department = Department::where('name', $presentation['department'])->first();

        $visit = $visits->open([
            'patient_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'department_id' => $department?->id,
            'reason' => $presentation['reason'],
            'complaints' => $presentation['complaints'],
            'receptionist_remarks' => $presentation['arrival'],
        ], $this->actor($this->desk));

        if ($scenario === 'registered') {
            return; // arrived, waiting to be seen — the top of the queue
        }

        // ── Triage ───────────────────────────────────────────────────────
        $visits->recordVitals($visit, $this->vitals($patient, $i));

        if ($scenario === 'triaged') {
            return; // vitals taken, waiting for the doctor
        }

        // ── Consultation ─────────────────────────────────────────────────
        $visits->updateClinical($visit->fresh(), [
            'doctor_user_id' => $this->doctor->id,
            'diagnosis' => $presentation['diagnosis'],
            'doctor_remarks' => $presentation['plan'],
        ]);

        $consultation = Service::where('name', $presentation['charge'])->first();
        if ($consultation !== null) {
            $billing->orderService($visit->fresh(), $consultation->id, 1, $by);
        }

        if ($scenario === 'consulting') {
            return; // seen, charged, more to do
        }

        // ── Everything that hangs off the consultation ───────────────────
        match ($scenario) {
            'labs_pending' => $this->labs($visit, $presentation, resulted: false),
            'labs_resulted' => $this->labs($visit, $presentation, resulted: true),
            'imaging_pending' => $this->imaging($visit, $presentation, reported: false),
            'imaging_reported' => $this->imaging($visit, $presentation, reported: true),
            'dispensed' => $this->dispense($visit, $presentation),
            'prescribed' => $this->prescribe($visit, $presentation),
            'admitted', 'discharged' => $this->stay($visit, $patient, $scenario === 'discharged', $i),
            default => $this->labs($visit, $presentation, resulted: true),
        };

        if (in_array($scenario, ['labs_pending', 'imaging_pending', 'prescribed', 'admitted'], true)) {
            return; // still open, work in flight
        }

        // ── The bill ─────────────────────────────────────────────────────
        $visit = $visit->fresh();

        if ($scenario !== 'discharged') {
            // A visit will not move to billing while work is still open, and
            // rightly: a bill raised over an unfinished order is a bill that
            // is about to change. Close them the way the wards do, then move.
            $this->closeOpenOrders($visit);
            $visits->advance($visit->fresh(), $by, 'Work complete — ready for billing.');
        }

        $visit = $visit->fresh();
        $discount = $i % 11 === 0 ? $this->money(5000) : '0.00';
        $invoice = $billing->generateInvoice($visit, $discount, $this->actor($this->cashier));

        // Billing → Payment. A visit does not walk itself from "there is a
        // bill" to "we are waiting to be paid" — somebody hands the bill over,
        // and that press is this. Without it every settled visit in the demo
        // sat in Billing with a paid invoice attached, which is a state the
        // panel can reach only by leaving the job half done.
        if ($scenario !== 'voided') {
            $visits->advance($visit->fresh(), $this->actor($this->cashier), 'Invoice issued to the patient.');
        }

        match ($scenario) {
            'awaiting_payment' => null, // issued, nothing paid — the debtors list
            'part_paid' => $billing->recordPayment(
                $invoice,
                PaymentMethod::MobileMoney,
                bcdiv((string) $invoice->total, '3', 2),
                [],
                $this->actor($this->cashier),
            ),
            'voided' => $this->voidIt($invoice),
            'insured' => $this->claim($patient, $invoice),
            'card_paid' => $this->payByCard($patient, $invoice),
            default => $billing->recordPayment(
                $invoice,
                $this->methodFor($i),
                (string) $invoice->balance,
                [],
                $this->actor($this->cashier),
            ),
        };

        // A settled bill closes its own visit; this is what makes that happen
        // on a seeded record exactly as it does on a real one.
        app(VisitService::class)->reconcile($visit->fresh(), $by);
    }

    /**
     * Finish every piece of work still showing as open on a visit.
     *
     * Through OrderService::complete, which is what the bench and the
     * dispensary call — so the demo's orders carry the same status history a
     * real one does rather than being nudged into place.
     */
    private function closeOpenOrders(Visit $visit): void
    {
        $orders = app(\App\Services\OrderService::class);

        $open = $visit->fresh()->orders()
            ->whereIn('status', [\App\Enums\OrderStatus::Pending->value, \App\Enums\OrderStatus::InProgress->value])
            ->get();

        foreach ($open as $order) {
            // An admission is the one order that stays open on purpose: it
            // closes at discharge, and closing it here would free a bed with
            // somebody still in it.
            if ($order->type === \App\Enums\OrderType::Admission) {
                continue;
            }

            $orders->complete($order, [], $this->doctor->id);
        }
    }

    /**
     * Cash is commonest, but a demo of one payment method proves nothing.
     *
     * Card is deliberately absent: paying by card debits a patient's card
     * ledger, and BillingService refuses if there is no card to debit. The
     * card route is demonstrated by the `card_paid` scenario, which issues
     * one first — which is the order a cashier does it in too.
     */
    private function methodFor(int $i): PaymentMethod
    {
        return match ($i % 7) {
            0, 1, 2 => PaymentMethod::Cash,
            3, 4 => PaymentMethod::MobileMoney,
            5 => PaymentMethod::Bank,
            default => PaymentMethod::Flutterwave,
        };
    }

    /**
     * What somebody came in with, and what was found.
     *
     * Written as complete clinical sentences rather than lorem ipsum: this is
     * the text on every screen a demonstration opens, and "Fever and headache
     * for three days" tells a viewer what the field is for in a way that
     * "Consectetur adipiscing" never will.
     *
     * @return array{department:string,reason:string,arrival:string,complaints:string,diagnosis:string,plan:string,charge:string,test:string,study:string,drug:string,dose:string}
     */
    private function presentation(int $i): array
    {
        $cases = [
            [
                'department' => 'Outpatient',
                'reason' => 'Fever and headache',
                'arrival' => 'Walked in at the triage desk. Temperature taken on arrival.',
                'complaints' => 'Fever for three days with headache, joint pain and poor appetite. No vomiting.',
                'diagnosis' => 'Uncomplicated malaria, confirmed by rapid diagnostic test.',
                'plan' => 'Start artemether/lumefantrine for three days. Paracetamol for fever. Review if fever persists beyond 48 hours.',
                'charge' => 'General consultation',
                'test' => 'Malaria RDT',
                'study' => 'Chest X-ray',
                'drug' => 'Artemether/Lumefantrine 20/120',
                'dose' => '4 tablets twice daily for 3 days, after food',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Cough and chest pain',
                'arrival' => 'Referred by the nearby health centre with a letter.',
                'complaints' => 'Productive cough for two weeks, worse at night. Chest pain on deep breathing. No night sweats.',
                'diagnosis' => 'Lower respiratory tract infection. Tuberculosis screening negative.',
                'plan' => 'Amoxicillin for five days. Chest X-ray reviewed. Return immediately if breathless or coughing blood.',
                'charge' => 'General consultation',
                'test' => 'Sputum for AFB',
                'study' => 'Chest X-ray',
                'drug' => 'Amoxicillin 250mg',
                'dose' => '2 capsules three times daily for 5 days',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Abdominal pain',
                'arrival' => 'Walked in. Asked to be seen by the same doctor as last time.',
                'complaints' => 'Burning upper abdominal pain for a month, worse when hungry, relieved by food.',
                'diagnosis' => 'Peptic ulcer disease. No alarm features on examination.',
                'plan' => 'Omeprazole for four weeks. Avoid NSAIDs and alcohol. Review in one month with symptoms diary.',
                'charge' => 'General consultation',
                'test' => 'Stool Analysis',
                'study' => 'Abdominal Ultrasound',
                'drug' => 'Omeprazole 20mg',
                'dose' => '1 capsule each morning before food for 28 days',
            ],
            [
                'department' => 'Maternity',
                'reason' => 'Antenatal review',
                'arrival' => 'Booked antenatal appointment. Card and previous scan brought along.',
                'complaints' => 'Routine second-trimester review. Feeling well, fetal movements present.',
                'diagnosis' => 'Normal singleton pregnancy at 24 weeks. Mild iron deficiency anaemia.',
                'plan' => 'Continue iron and folic acid. Obstetric scan reviewed. Next review in four weeks.',
                'charge' => 'Antenatal review',
                'test' => 'Full Blood Count',
                'study' => 'Obstetric Ultrasound',
                'drug' => 'Ferrous sulphate + folic acid',
                'dose' => '1 tablet daily for 30 days, after food',
            ],
            [
                'department' => 'Paediatrics',
                'reason' => 'Diarrhoea and vomiting',
                'arrival' => 'Brought in by the mother. Seen ahead of the queue on triage.',
                'complaints' => 'Loose stools six times today with two episodes of vomiting. Still drinking. No blood in stool.',
                'diagnosis' => 'Acute gastroenteritis with mild dehydration.',
                'plan' => 'Oral rehydration salts after every loose stool, zinc for ten days. Return same day if unable to drink.',
                'charge' => 'General consultation',
                'test' => 'Stool Analysis',
                'study' => 'Abdominal Ultrasound',
                'drug' => 'Oral Rehydration Salts',
                'dose' => '1 sachet in 1 litre of clean water, after each loose stool',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Blood pressure review',
                'arrival' => 'Scheduled three-month review. Own BP diary handed in at reception.',
                'complaints' => 'Three-month review. Taking medication daily, no headaches or swelling of the legs.',
                'diagnosis' => 'Hypertension, adequately controlled on current dose.',
                'plan' => 'Continue amlodipine. Reduce added salt. Repeat renal function and lipids in six months.',
                'charge' => 'Specialist consultation',
                'test' => 'Renal Function Tests',
                'study' => 'Echocardiogram',
                'drug' => 'Amlodipine 5mg',
                'dose' => '1 tablet each morning, continuing',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Diabetes review',
                'arrival' => 'Scheduled review. Fasting since last night for bloods.',
                'complaints' => 'Passing urine frequently at night. Tired in the afternoons. Weight stable.',
                'diagnosis' => 'Type 2 diabetes with suboptimal control (HbA1c above target).',
                'plan' => 'Increase metformin to twice daily. Dietary advice given. Repeat HbA1c in three months.',
                'charge' => 'Specialist consultation',
                'test' => 'HbA1c',
                'study' => 'Abdominal Ultrasound',
                'drug' => 'Metformin 500mg',
                'dose' => '1 tablet twice daily with meals',
            ],
            [
                'department' => 'Surgery',
                'reason' => 'Wound on the leg',
                'arrival' => 'Walked in to the treatment room. Dressing already soaked through.',
                'complaints' => 'Cut on the right shin from a fall four days ago. Now painful with some discharge.',
                'diagnosis' => 'Infected laceration of the right shin. No underlying fracture on X-ray.',
                'plan' => 'Wound cleaned and dressed. Antibiotics for five days. Daily dressing for one week.',
                'charge' => 'Wound dressing',
                'test' => 'Full Blood Count',
                'study' => 'Limb X-ray',
                'drug' => 'Metronidazole 400mg',
                'dose' => '1 tablet three times daily for 5 days',
            ],
            [
                'department' => 'Dental',
                'reason' => 'Toothache',
                'arrival' => 'Walked in first thing. In visible pain, seen ahead of the queue.',
                'complaints' => 'Severe pain in a lower right molar for five days, keeping her awake. Face slightly swollen.',
                'diagnosis' => 'Dental abscess of the lower right first molar.',
                'plan' => 'Tooth extracted under local anaesthetic. Antibiotics and analgesia. Salt-water rinses from tomorrow.',
                'charge' => 'Tooth extraction',
                'test' => 'Full Blood Count',
                'study' => 'Limb X-ray',
                'drug' => 'Ibuprofen 400mg',
                'dose' => '1 tablet three times daily for 3 days, after food',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Difficulty in breathing',
                'arrival' => 'Arrived by boda. Taken straight to the treatment room.',
                'complaints' => 'Wheeze and chest tightness since last night, worse on exertion. Known asthmatic.',
                'diagnosis' => 'Acute asthma exacerbation, moderate. Responded well to nebulisation.',
                'plan' => 'Nebulised salbutamol given in the treatment room. Inhaler technique reviewed. Written action plan issued.',
                'charge' => 'Nebulisation',
                'test' => 'Full Blood Count',
                'study' => 'Chest X-ray',
                'drug' => 'Salbutamol inhaler',
                'dose' => '2 puffs when needed, up to four times daily',
            ],
            [
                'department' => 'Eye clinic',
                'reason' => 'Blurred vision',
                'arrival' => 'Self-referred to the eye clinic after a community screening.',
                'complaints' => 'Gradual blurring of distance vision over six months. No pain or redness.',
                'diagnosis' => 'Refractive error (myopia). No diabetic retinopathy seen.',
                'plan' => 'Prescription for spectacles issued. Annual review, sooner if vision changes.',
                'charge' => 'Eye refraction',
                'test' => 'Random Blood Sugar',
                'study' => 'CT Head (non-contrast)',
                'drug' => 'Paracetamol 500mg',
                'dose' => '2 tablets when needed for headache, up to four times daily',
            ],
            [
                'department' => 'Outpatient',
                'reason' => 'Fever in a returning traveller',
                'arrival' => 'Walked in this morning. Travel history taken at the desk.',
                'complaints' => 'Fever and chills two days after returning from the north. Some nausea, no rash.',
                'diagnosis' => 'Malaria, parasitaemia seen on blood slide. No features of severe disease.',
                'plan' => 'Full antimalarial course. Encourage fluids. Return if vomiting or unable to take tablets.',
                'charge' => 'General consultation',
                'test' => 'Blood Slide for Malaria',
                'study' => 'Abdominal Ultrasound',
                'drug' => 'Artemether/Lumefantrine 20/120',
                'dose' => '4 tablets twice daily for 3 days, after food',
            ],
        ];

        return $cases[$i % count($cases)];
    }

    /** Plausible observations, varied by age so the charts are not a flat line. */
    private function vitals(Patient $patient, int $i): array
    {
        $dob = $patient->dob;
        $age = $dob instanceof \Illuminate\Support\Carbon ? $dob->age : 30;
        $child = $age < 13;

        return [
            'temperature' => round(36.4 + (($i % 7) * 0.42), 1),
            'blood_pressure' => $child
                ? (95 + $i % 12).'/'.(60 + $i % 8)
                : (108 + ($i * 3) % 46).'/'.(68 + ($i * 2) % 24),
            'pulse' => $child ? 92 + $i % 24 : 62 + ($i * 5) % 38,
            'respiratory_rate' => $child ? 22 + $i % 8 : 14 + $i % 8,
            'spo2' => 94 + $i % 6,
            'weight' => $child ? round(8 + $age * 2.6, 1) : round(52 + ($i * 7) % 42, 1),
            'height' => $child ? 70 + $age * 6 : 152 + ($i * 3) % 32,
        ];
    }

    // ── The pieces an episode is made of ─────────────────────────────────

    private function labs(Visit $visit, array $presentation, bool $resulted): void
    {
        $test = LabTest::where('name', $presentation['test'])->first();
        if ($test === null) {
            return;
        }

        $labs = app(LabService::class);
        $technician = $this->technician;
        $order = $labs->order($visit->fresh(), [$test->id], 'Requested at consultation: '.Str::lower($presentation['reason']).'.', $this->doctor->id);

        if (! $resulted) {
            // Left on the bench, which is where most orders are at any moment.
            $labs->transition($order, LabOrderStatus::Collected, $this->actor($technician));

            return;
        }

        $labs->transition($order, LabOrderStatus::Collected, $this->actor($technician));
        $labs->transition($order->fresh(), LabOrderStatus::Processing, $this->actor($technician));

        foreach ($order->fresh()->items as $item) {
            $labs->recordResult($item, [
                'result_value' => $test->reference_range === 'Negative' ? 'Positive' : '9.4',
                'result_flag' => $test->reference_range === 'Negative' ? ResultFlag::Abnormal->value : ResultFlag::Normal->value,
                'result_notes' => 'Reported by the on-duty technician. Clinician informed.',
            ], $this->actor($technician));
        }

        $labs->transition($order->fresh(), LabOrderStatus::Completed, $this->actor($technician));
    }

    private function imaging(Visit $visit, array $presentation, bool $reported): void
    {
        $study = RadiologyStudy::where('name', $presentation['study'])->first();
        if ($study === null) {
            return;
        }

        $radiology = app(RadiologyService::class);
        $radiographer = $this->radiographer;
        $order = $radiology->order($visit->fresh(), [$study->id], $presentation['reason'].' — clinical correlation requested.', $this->doctor->id);

        if (! $reported) {
            $radiology->transition($order, RadiologyOrderStatus::Scheduled, $this->actor($radiographer));

            return;
        }

        $radiology->transition($order, RadiologyOrderStatus::Scheduled, $this->actor($radiographer));
        $radiology->transition($order->fresh(), RadiologyOrderStatus::Performed, $this->actor($radiographer));
        $radiology->recordReport(
            $order->fresh(),
            'Images are of diagnostic quality. No focal abnormality of the area examined. Soft tissues and bony structures appear unremarkable.',
            'No acute abnormality demonstrated. Correlate with clinical findings.',
            $this->actor($radiographer),
        );
        $radiology->transition($order->fresh(), RadiologyOrderStatus::Reported, $this->actor($radiographer));
    }

    private function dispense(Visit $visit, array $presentation): void
    {
        $drug = StockItem::where('name', $presentation['drug'])->first();
        if ($drug === null) {
            return;
        }

        app(DispensationService::class)->dispense(
            $visit->fresh(),
            [['stock_item_id' => $drug->id, 'quantity' => '12']],
            $presentation['dose'].'.',
            $this->actor($this->pharmacist),
        );
    }

    private function prescribe(Visit $visit, array $presentation): void
    {
        app(PrescriptionService::class)->prescribe($visit->fresh(), [[
            'drug_name' => $presentation['drug'],
            'dosage' => Str::before($presentation['dose'], ' '),
            'slots' => ['morning', 'evening'],
            'days' => 5,
            'start_date' => Carbon::now()->toDateString(),
            'instructions' => $presentation['dose'].'.',
        ]], 'Complete the full course even once symptoms settle.', $this->doctor->id);
    }

    /**
     * An inpatient stay, billed a night at a time as a real one is.
     *
     * The nightly accrual is run explicitly rather than waited for: the
     * scheduled command posts one night each morning, and a demo seeded at
     * noon cannot wait three days for a bed charge to appear.
     */
    private function stay(Visit $visit, Patient $patient, bool $discharge, int $i): void
    {
        $bed = Bed::where('status', 'available')->orderBy('id')->first();
        if ($bed === null) {
            return;
        }

        $admissions = app(AdmissionService::class);
        $nights = 2 + $i % 4;

        $admission = $admissions->admit($patient, $bed, [
            'visit_id' => $visit->id,
            'admitted_at' => Carbon::now()->subDays($nights)->setTime(14, 30)->toDateTimeString(),
            'admitting_doctor_id' => $this->doctor->id,
            'reason' => 'Requires inpatient observation and intravenous treatment.',
            'title' => 'Inpatient stay',
        ], $this->doctor->id);

        // Every night up to now, as the 00:05 job would have posted them.
        $admissions->accrueNightlyCharges($admission->fresh(), Carbon::now(), $this->actor($this->nurse));

        if ($discharge) {
            $admissions->discharge(
                $admission->fresh(),
                AdmissionStatus::Discharged,
                'Observations stable for 24 hours, tolerating oral intake. Discharged home with a review in one week.',
                $this->doctor->id,
            );
        }
    }

    private function voidIt(\App\Models\Invoice $invoice): void
    {
        // Raised against the wrong visit and cancelled — the state a billing
        // screen has to be able to show without it looking like a bug.
        $invoice->forceFill([
            'status' => \App\Enums\InvoiceStatus::Void,
            'notes' => 'Raised in error against the wrong visit. Replaced by a corrected invoice.',
        ])->save();
    }

    private function claim(Patient $patient, \App\Models\Invoice $invoice): void
    {
        $provider = InsuranceProvider::orderBy('id')->first();
        if ($provider === null) {
            return;
        }

        $claims = app(InsuranceService::class);
        $claim = $claims->createClaim([
            'patient_id' => $patient->id,
            'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id,
            'amount' => (string) $invoice->balance,
            'notes' => 'Submitted with the discharge note and itemised bill attached.',
        ], $this->actor($this->cashier));

        // Claims sit at every stage of the chase at once, because chasing them
        // is most of the job and a screen where they are all drafts cannot
        // show that. Keyed off the claim's own id so the spread is fixed.
        $desk = $this->actor($this->cashier);

        match ($claim->id % 4) {
            0 => null, // still being put together
            1 => $claims->transition($claim, ClaimStatus::Submitted, $desk, 'Submitted through the insurer portal.'),
            2 => $claims->transition(
                $claims->transition($claim, ClaimStatus::Submitted, $desk, 'Submitted through the insurer portal.'),
                ClaimStatus::Approved,
                $desk,
                'Approved in full. Remittance expected with the month-end run.',
            ),
            default => $claims->transition(
                $claims->transition($claim, ClaimStatus::Submitted, $desk, 'Submitted through the insurer portal.'),
                ClaimStatus::Rejected,
                $desk,
                'Rejected — the member’s cover had lapsed on the date of attendance. To be re-billed to the patient.',
            ),
        };
    }

    private function payByCard(Patient $patient, \App\Models\Invoice $invoice): void
    {
        $cards = app(CardService::class);
        $card = $cards->issue($patient, [
            'accepts_credit' => true,
            'max_credit' => $this->money(100000),
        ], $this->actor($this->cashier));

        $cards->credit($card, $this->money(150000), 'Top-up at the cashier', $this->actor($this->cashier));

        // The payment debits the card — recordPayment does that itself, given
        // the card. Debiting by hand first and then recording a payment would
        // have taken the money off twice.
        app(BillingService::class)->recordPayment(
            $invoice,
            PaymentMethod::Card,
            (string) $invoice->balance,
            ['card' => $card->fresh()],
            $this->actor($this->cashier),
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    //  The diary
    // ═════════════════════════════════════════════════════════════════════

    /**
     * A diary with a past and a future.
     *
     * Past appointments carry an outcome — attended, or not — because an
     * appointments screen where nothing has ever happened cannot show what the
     * no-show rate is for. Future ones fill today and the coming fortnight so
     * the queue screen opens with work on it.
     */
    private function appointments(array $roster): void
    {
        if (\App\Models\Appointment::count() > 20) {
            return; // already seeded
        }

        $service = app(AppointmentService::class);
        $patients = collect($roster)->filter(fn (Patient $p) => $p->status === PatientStatus::Active)->values();
        if ($patients->isEmpty()) {
            return;
        }

        $reasons = [
            'Follow-up review', 'Blood pressure check', 'Antenatal review',
            'Dressing change', 'Results review', 'Diabetes review',
            'Immunisation', 'Post-operative review', 'Spectacles collection',
        ];

        $sources = ['phone', 'walk_in', 'online', 'referral'];

        // 14 days behind, 14 days ahead.
        for ($offset = -14; $offset <= 14; $offset++) {
            $day = Carbon::now()->addDays($offset);

            // The doctor's windows are Monday to Friday.
            if ($day->isWeekend()) {
                continue;
            }

            // Two a day is enough to look like a diary and cheap to seed.
            foreach ([0, 1] as $slot) {
                $index = abs($offset) * 2 + $slot;
                $patient = $patients[$index % $patients->count()];
                $at = $day->copy()->setTime(9 + $slot * 2, 0);

                try {
                    $appointment = $service->book([
                        'patient_id' => $patient->id,
                        'doctor_user_id' => $this->doctor->id,
                        'department_id' => Department::where('name', 'Outpatient')->value('id'),
                        'scheduled_at' => $at->toDateTimeString(),
                        'duration_minutes' => 30,
                        'source' => $sources[$index % count($sources)],
                        'reason' => $reasons[$index % count($reasons)],
                    ], $this->actor($this->desk));
                } catch (\Throwable) {
                    continue; // slot taken or outside the window — try the next
                }

                // What happened, for the ones that are in the past.
                if ($offset >= 0) {
                    if ($offset <= 2 && $index % 4 === 0) {
                        $service->transition($appointment, AppointmentStatus::Confirmed, $this->actor($this->desk), 'Confirmed by phone.');
                    }

                    continue;
                }

                match ($index % 5) {
                    0, 1, 2 => $this->attended($appointment),
                    3 => $service->transition($appointment, AppointmentStatus::NoShow, $this->actor($this->desk), 'Did not attend; no contact.'),
                    default => $service->transition($appointment, AppointmentStatus::Cancelled, $this->actor($this->desk), 'Cancelled by the patient — travelling.'),
                };
            }
        }
    }

    /**
     * An appointment that was kept, walked through every state it passes.
     *
     * The status graph refuses Scheduled → Completed, and rightly: an
     * appointment nobody checked in cannot have been attended. Seeding the
     * end state by jumping the graph would have produced demo records no
     * screen in the system could have produced.
     */
    private function attended(\App\Models\Appointment $appointment): void
    {
        $service = app(AppointmentService::class);
        $desk = $this->actor($this->desk);

        $service->transition($appointment, AppointmentStatus::Confirmed, $desk, 'Confirmed by phone the day before.');
        $service->transition($appointment->fresh(), AppointmentStatus::CheckedIn, $desk, 'Arrived and checked in at reception.');

        // Completed is not a status somebody sets — it is what happens when
        // the write-up is saved. recordOutcome opens the visit, raises the
        // consultation order, writes the report and closes both.
        $service->recordOutcome(
            $appointment->fresh(),
            'Reviewed as planned. Symptoms settling, observations within normal limits. '
                .'Medication continued at the same dose; next review in three months, sooner if anything changes.',
            [],
            $this->doctor->id,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Things that stand between visits
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Arrangements that outlive an episode: an insurer float with members
     * drawing on it, a family card, and a treatment history.
     */
    private function standingArrangements(array $roster): void
    {
        $provider = InsuranceProvider::where('name', 'Jubilee Health')->first();
        $patients = collect($roster)
            ->filter(fn (Patient $p) => $p->status === PatientStatus::Active)
            ->take(12)
            ->values();

        if ($provider === null || $patients->count() < 4) {
            return;
        }

        $cards = app(CardService::class);
        $ledger = app(InsuranceLedgerService::class);
        $by = $this->actor($this->cashier);

        if ($provider->transactions()->count() === 0) {
            $ledger->deposit($provider, $this->money(3000000), 'RTGS-2026-0417', 'Quarterly float', $by);
        }

        // Member cards drawing on that float, some of them in debt — which is
        // what the insurer's "clear" button on the provider page is for.
        foreach ($patients->take(6) as $n => $patient) {
            if ($patient->cards()->count() > 0) {
                continue;
            }

            // Terms stated here rather than inherited: a member card is meant
            // to run negative, and leaving that to whatever the insurer row
            // happens to hold makes the demo depend on data it did not write.
            $card = $cards->issue($patient, [
                'insurance_provider_id' => $provider->id,
                'member_no' => 'JH-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
                'accepts_credit' => true,
                'max_credit' => $this->money(200000),
            ], $by);

            $cards->debit($card, $this->money(45000 + $n * 20000), 'Outpatient attendance', $by);
        }

        // A family card: one balance, several people allowed to spend it.
        $holder = $patients[7] ?? null;
        $spouse = $patients[8] ?? null;
        $child = $patients[9] ?? null;

        if ($holder !== null && $holder->cards()->count() === 0) {
            $family = $cards->issue($holder, [
                'accepts_credit' => true,
                'max_credit' => $this->money(50000),
            ], $by);

            $cards->credit($family, $this->money(400000), 'Family account opening balance', $by);

            if ($spouse !== null) {
                $cards->addHolder($family, $spouse, CardHolderRelationship::Spouse, $by);
                $cards->debit($family->fresh(), $this->money(35000), 'Consultation', $by, ['for' => $spouse->id]);
            }
            if ($child !== null) {
                $cards->addHolder($family, $child, CardHolderRelationship::Child, $by);
                $cards->debit($family->fresh(), $this->money(18000), 'Immunisation visit', $by, ['for' => $child->id]);
            }
        }

        // Procedures worth keeping a record of outside a visit.
        $treatments = app(TreatmentService::class);
        $procedures = [
            ['Wound dressing', 'Clean dressing applied to the right shin. Granulating well, no signs of infection.'],
            ['Suture removal', 'Eight sutures removed from the forearm. Wound fully healed.'],
            ['Plaster application', 'Below-knee cast applied for an undisplaced fracture. Neurovascular status intact.'],
            ['Ear syringing', 'Both ears syringed. Hearing much improved and drums visible.'],
        ];

        foreach ($patients->take(4) as $n => $patient) {
            if ($patient->treatmentRecords()->count() > 0) {
                continue;
            }

            [$procedure, $notes] = $procedures[$n % count($procedures)];
            $treatments->create($patient, [
                'procedure' => $procedure,
                'notes' => $notes,
                'performed_at' => Carbon::now()->subDays(3 + $n * 5)->setTime(11, 0)->toDateTimeString(),
            ], [], $this->actor($this->nurse));
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═════════════════════════════════════════════════════════════════════

    /**
     * firstOrCreate within this tenant.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function make(string $model, array $lookup, array $extra)
    {
        $existing = $model::query()->where($lookup)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $model::create($lookup + $extra);
    }

    /**
     * Create the row, or correct the one that is already there.
     *
     * The catalogue belongs to this seeder, and the demo hospital's currency
     * can change under it — a price list written in shillings and then read as
     * dollars says a consultation costs twenty thousand of them. `make()`
     * would have left those alone, because it only ever creates. Structural
     * rows (departments, rooms, staff) still use `make()`: those are somebody
     * else's to edit once they exist.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function sync(string $model, array $lookup, array $extra)
    {
        $existing = $model::query()->where($lookup)->first();

        if ($existing !== null) {
            $existing->forceFill($extra)->save();

            return $existing;
        }

        return $model::create($lookup + $extra);
    }

    /** A stock item with the opening movement that explains its balance. */
    private function stock(
        StockCategory $category,
        string $name,
        string $unit,
        string $quantity,
        int $cost,
        int $sale,
        string $reorder,
        ?string $expiry,
    ): void {
        $costPrice = $this->money($cost);
        $existing = StockItem::where('name', $name)->first();

        if ($existing !== null) {
            // Prices are corrected; quantities are not. What is on the shelf
            // is the ledger's business, and rewriting a balance here would
            // leave it disagreeing with the movements that explain it.
            $existing->forceFill([
                'cost_price' => $costPrice,
                'sale_price' => $this->money($sale),
                'current_stock_value' => bcmul((string) $existing->current_quantity, $costPrice, 2),
            ])->save();

            return;
        }

        $item = StockItem::create([
            'uuid' => (string) Str::uuid(),
            'stock_category_id' => $category->id,
            'name' => $name,
            'unit' => $unit,
            'original_quantity' => $quantity,
            'current_quantity' => $quantity,
            'cost_price' => $costPrice,
            'sale_price' => $this->money($sale),
            'current_stock_value' => bcmul($quantity, $costPrice, 2),
            'reorder_level' => $reorder,
            'expiry_date' => $expiry === null ? null : Carbon::now()->modify($expiry)->toDateString(),
            'is_active' => true,
        ]);

        StockMovement::create([
            'stock_item_id' => $item->id,
            'reason' => 'opening_stock',
            'quantity' => $quantity,
            'balance_after' => $quantity,
            'unit_cost' => $costPrice,
            'created_at' => Carbon::now()->subDays(self::DAYS_OF_HISTORY),
        ]);
    }
}
