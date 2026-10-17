<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Models\Scopes\HospitalScope;
use Database\Seeders\DemoScenarios\PatientLinks;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo's patient links (database/seeders/DemoScenarios/PatientLinks.php) on the seeded demo hospital. */
class PatientLinksDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_has_links_to_its_own_patients_documents_and_running_it_twice_adds_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        PatientLinks::run($hospital);
        $links = PatientLink::withoutGlobalScope(HospitalScope::class)->where('hospital_id', $hospital->id)->get();
        $this->assertGreaterThan(0, $links->count());

        foreach ($links as $link) {
            // Each link is to its own patient's document, in this hospital.
            $this->assertSame($hospital->id, $link->patient()->withoutGlobalScopes()->value('hospital_id'));
            $this->assertNotNull($link->reference);
        }
        $this->assertGreaterThan(0, PatientLinkOpen::withoutGlobalScope(HospitalScope::class)->where('hospital_id', $hospital->id)->count());

        PatientLinks::run($hospital);
        $this->assertSame($links->count(), PatientLink::withoutGlobalScope(HospitalScope::class)->where('hospital_id', $hospital->id)->count());
    }
}
