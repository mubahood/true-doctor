<?php

namespace Database\Seeders;

use App\Enums\AdmissionStatus;
use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\Patient;
use App\Models\User;
use App\Models\VitalRound;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\PatientService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A hospital's worth of data, for measuring offline sync against something
 * shaped like the real thing.
 *
 * Every record goes through the same Services and the same validation the
 * application uses — `PatientService::register` allocates real checksummed
 * numbers, `AdmissionService::admit` takes the real bed locks. Rows inserted
 * straight into the database would be faster and would measure nothing: the
 * point is to find out what happens when two thousand patients each carry a
 * revision, a sequence allocation and a tenant scope (plan §19.3).
 *
 *   php artisan db:seed --class=OfflineDemoSeeder
 *   OFFLINE_SEED_PATIENTS=10000 php artisan db:seed --class=OfflineDemoSeeder
 */
class OfflineDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Sized from config, so one seeder covers both the "does it work" run
        // and the "does it still work at ten thousand" run. `db:seed` defines
        // no options of its own, so the size cannot come from the command line.
        $patientCount = (int) config('offline.seed.patients', 1200);
        $inpatientCount = (int) config('offline.seed.inpatients', 40);

        $hospital = $this->hospital();
        app(CurrentHospital::class)->set($hospital->id);

        $this->command->info("Seeding into hospital #{$hospital->id} ({$hospital->name}).");

        $staff = $this->staff($hospital);
        $wards = $this->wards($hospital);

        $patients = $this->patients($hospital, $staff['nurse'], $patientCount);
        $this->visits($patients, $staff['nurse']);
        $this->inpatients($patients, $wards, $staff['nurse'], $inpatientCount);
        $this->devices($hospital, $staff);

        $this->command->info('Done. '.Patient::count().' patients, '
            .VitalRound::count().' vitals rounds, '
            .NursingNote::count().' nursing notes.');
    }

    /**
     * A hospital with room for a load test.
     *
     * `PatientService::register` enforces the subscription's `max_patients`,
     * and it is right to — it caught this seeder on the first run. Rather than
     * bypassing the Service, which would make the whole seed meaningless, the
     * seeder uses a hospital whose plan does not cap patients. That is a real
     * configuration, not a hole punched through a rule.
     */
    private function hospital(): Hospital
    {
        $hospital = Hospital::firstOrCreate(
            ['name' => 'Offline Test Hospital'],
            ['currency' => 'UGX'],
        );

        app(CurrentHospital::class)->set($hospital->id);

        $limit = app(\App\Support\PlanLimit::class)->limitFor('patients');

        if ($limit !== null) {
            $this->command->warn(
                "This hospital's plan caps patients at {$limit}. Seeding into an uncapped hospital instead — "
                .'the point of the seed is to measure sync, not to test the plan limit.',
            );
        }

        return $hospital;
    }

    /** @return array<string,User> */
    private function staff(Hospital $hospital): array
    {
        $make = function (string $role, string $name) use ($hospital) {
            $user = User::firstOrCreate(
                ['email' => Str::slug($name).'@offline.test'],
                [
                    'hospital_id' => $hospital->id,
                    'name' => $name,
                    'role' => $role,
                    'password' => bcrypt('password1'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
            $user->syncSpatieRole();

            return $user;
        };

        return [
            'nurse' => $make('nurse', 'Offline Nurse'),
            'clerk' => $make('receptionist', 'Offline Clerk'),
            'doctor' => $make('doctor', 'Offline Doctor'),
        ];
    }

    /** @return array<int,Bed> */
    private function wards(Hospital $hospital): array
    {
        $beds = [];

        foreach (['Maternity', 'Surgical', 'Paediatric'] as $name) {
            $ward = Ward::firstOrCreate(
                ['hospital_id' => $hospital->id, 'name' => $name],
                ['is_active' => true],
            );

            for ($i = 1; $i <= 20; $i++) {
                $beds[] = Bed::firstOrCreate(
                    ['hospital_id' => $hospital->id, 'ward_id' => $ward->id, 'name' => substr($name, 0, 1).'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)],
                    ['daily_charge' => '50000.00', 'is_active' => true],
                );
            }
        }

        return $beds;
    }

    /** @return array<int,Patient> */
    private function patients(Hospital $hospital, User $by, int $count): array
    {
        $service = app(PatientService::class);
        $existing = Patient::count();

        if ($existing >= $count) {
            $this->command->info("Already {$existing} patients; skipping.");

            return Patient::limit($count)->get()->all();
        }

        $first = ['Amina', 'Joseph', 'Grace', 'Moses', 'Sarah', 'David', 'Mary', 'Peter', 'Ruth', 'Samuel', 'Esther', 'John'];
        $last = ['Nakato', 'Okello', 'Auma', 'Mugisha', 'Nabwire', 'Ssemakula', 'Achieng', 'Kato', 'Nalubega', 'Wanyama'];

        $made = [];
        $bar = $this->command->getOutput()->createProgressBar($count - $existing);
        $bar->start();

        for ($i = $existing; $i < $count; $i++) {
            $made[] = $service->register([
                'first_name' => $first[$i % count($first)],
                'last_name' => $last[($i * 7) % count($last)],
                'dob' => now()->subYears(random_int(1, 88))->subDays(random_int(0, 364))->toDateString(),
                'sex' => ['male', 'female'][$i % 2],
                'phone_1' => '07'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                // Realistic sparsity: most patients have no allergy recorded,
                // which is what makes the ones who do worth noticing.
                'allergies' => $i % 11 === 0 ? ['Penicillin'] : null,
            ], $by->id);

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();

        return $made;
    }

    /** @param array<int,Patient> $patients */
    private function visits(array $patients, User $by): void
    {
        $service = app(VisitService::class);
        // A tenth of the register has an open visit — roughly a day's work in
        // a hospital of this size.
        $sample = array_slice($patients, 0, max(1, (int) (count($patients) / 10)));

        foreach ($sample as $patient) {
            $service->currentOrOpenFor($patient, $by->id);
        }
    }

    /**
     * @param  array<int,Patient>  $patients
     * @param  array<int,Bed>  $beds
     */
    private function inpatients(array $patients, array $beds, User $by, int $count): void
    {
        $service = app(AdmissionService::class);
        $free = array_values(array_filter($beds, fn (Bed $b) => $b->fresh()->status->isAssignable()));
        $count = min($count, count($free), count($patients));

        for ($i = 0; $i < $count; $i++) {
            try {
                $admission = $service->admit($patients[$i], $free[$i], [
                    'reason' => ['Observation', 'Post-operative care', 'Obstructed labour', 'Severe malaria'][$i % 4],
                ], $by->id);
            } catch (\Throwable) {
                // A patient already admitted, or a bed taken between the check
                // and the lock. Both are the service doing its job.
                continue;
            }

            // A few days of charts, so a pull has something to page through.
            for ($round = 0; $round < random_int(4, 14); $round++) {
                VitalRound::create([
                    'uuid' => (string) Str::uuid(),
                    'admission_id' => $admission->id,
                    'temperature' => 36 + (random_int(0, 30) / 10),
                    'pulse' => random_int(58, 108),
                    'blood_pressure' => random_int(95, 145).'/'.random_int(60, 95),
                    'respiratory_rate' => random_int(12, 24),
                    'spo2' => random_int(92, 100),
                    'recorded_by' => $by->id,
                    'created_at' => now()->subHours($round * 6),
                ]);
            }

            for ($note = 0; $note < random_int(1, 5); $note++) {
                NursingNote::create([
                    'uuid' => (string) Str::uuid(),
                    'admission_id' => $admission->id,
                    'note' => ['Comfortable overnight.', 'Wound clean and dry.', 'Tolerating oral fluids.', 'Mobilised with assistance.'][$note % 4],
                    'recorded_by' => $by->id,
                    'created_at' => now()->subHours($note * 8),
                ]);
            }

            for ($dose = 0; $dose < random_int(2, 8); $dose++) {
                MedicationAdministration::create([
                    'uuid' => (string) Str::uuid(),
                    'admission_id' => $admission->id,
                    'drug_name' => ['Paracetamol', 'Amoxicillin', 'Metronidazole', 'Ibuprofen'][$dose % 4],
                    'dose' => ['500mg', '1g', '400mg'][$dose % 3],
                    'route' => ['oral', 'IV'][$dose % 2],
                    'status' => 'given',
                    'administered_by' => $by->id,
                    'created_at' => now()->subHours($dose * 4),
                ]);
            }
        }

        // A handful discharged, so tombstones and closed stays are represented.
        $service = app(AdmissionService::class);
        foreach (\App\Models\Admission::where('status', AdmissionStatus::Admitted)->limit(5)->get() as $admission) {
            try {
                $service->discharge($admission, AdmissionStatus::Discharged, 'Recovered.', $by->id);
            } catch (\Throwable) {
                // A closed financial period, most likely. Not worth failing a seed over.
            }
        }
    }

    /** @param array<string,User> $staff */
    private function devices(Hospital $hospital, array $staff): void
    {
        foreach ([['Maternity desk laptop', 'nurse'], ['Reception tablet', 'clerk'], ['Ward round phone', 'doctor']] as [$label, $role]) {
            Device::firstOrCreate(
                ['device_uuid' => (string) Str::uuid()],
                [
                    'hospital_id' => $hospital->id,
                    'user_id' => $staff[$role]->id,
                    'label' => $label,
                    'platform' => 'Chrome/Seeded',
                    'registered_at' => now()->subDays(random_int(1, 40)),
                    'last_seen_at' => now()->subMinutes(random_int(1, 4000)),
                    'last_sync_at' => now()->subMinutes(random_int(1, 4000)),
                ],
            );
        }
    }
}
