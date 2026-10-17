<?php

namespace Tests\Feature;

use App\Enums\PatientSex;
use App\Models\AncContact;
use App\Models\GrowthMeasurement;
use App\Models\Hospital;
use App\Models\Immunisation;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Services\GrowthService;
use App\Services\ImmunisationService;
use App\Services\PregnancyService;
use App\Support\CurrentHospital;
use Database\Seeders\DemoScenarios\MotherAndChild;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The maternity and child health demo scenario on the seeded demo hospital (docs/mother-child.md). */
class MotherAndChildDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fits_each_patient_shows_every_state_and_is_safe_to_run_twice(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        app(CurrentHospital::class)->set($hospital->id);

        // The register's people as DemoPopulationSeeder makes them (by age and sex).
        $make = fn (string $first, string $sex, int $years) => Patient::factory()->create([
            'hospital_id' => $hospital->id, 'first_name' => $first, 'sex' => $sex, 'status' => 'active',
            'dob' => now()->subYears($years)->subDays(20)->toDateString(),
        ]);
        foreach ([['Winnie', 'female', 22], ['Lydia', 'female', 24], ['Betty', 'female', 30], ['Amina', 'female', 2], ['Joel', 'male', 4]] as [$n, $s, $y]) {
            $make($n, $s, $y);
        }
        $make('Ruth', 'female', 74);
        $make('Isaac', 'male', 33);
        $make('Grace', 'female', 6);

        MotherAndChild::run($hospital);
        $counts = [Pregnancy::count(), AncContact::count(), Immunisation::count(), GrowthMeasurement::count()];
        MotherAndChild::run($hospital);
        $this->assertSame($counts, [Pregnancy::count(), AncContact::count(), Immunisation::count(), GrowthMeasurement::count()], 'a second run adds nothing');

        $this->assertSame(3, Pregnancy::count());
        foreach (Pregnancy::with('patient')->get() as $p) {
            $this->assertSame(PatientSex::Female, $p->patient->sex);
            $this->assertTrue($p->patient->dob->age >= 15 && $p->patient->dob->age <= 49, $p->patient->first_name.' is not of child-bearing age');
        }
        foreach ([Immunisation::with('patient')->get(), GrowthMeasurement::query()->get()] as $rows) {
            foreach ($rows as $row) {
                $this->assertLessThan(5, Patient::query()->findOrFail($row->patient_id)->dob->age);
            }
        }

        // Every state somebody would want to be shown.
        $statuses = Pregnancy::all()->map(fn ($p) => $p->status->value)->all();
        $this->assertContains('delivered', $statuses);
        $late = Pregnancy::query()->where('gravida', 1)->firstOrFail();
        $this->assertSame('missed', app(PregnancyService::class)->schedule($late)['contacts'][0]['status']);

        $overdue = collect(Patient::query()->whereIn('first_name', ['Amina', 'Joel'])->get())
            ->sum(fn ($c) => ImmunisationService::counts(app(ImmunisationService::class)->card($c))['overdue']);
        $this->assertGreaterThan(0, $overdue);
        $faltering = Patient::query()->whereIn('first_name', ['Amina', 'Joel'])->get()
            ->filter(fn ($c) => app(GrowthService::class)->assessment($c)['faltering']['flag']);
        $this->assertCount(1, $faltering);
    }
}
