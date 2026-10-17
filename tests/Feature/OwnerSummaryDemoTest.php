<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\OwnerSummary;
use App\Models\User;
use App\Services\OwnerSummaryService;
use App\Support\CurrentHospital;
use Database\Seeders\DemoScenarios\OwnerSummary as OwnerSummaryScenario;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The owner's daily summary on the seeded demo hospital (DemoScenarios\OwnerSummary). */
class OwnerSummaryDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scenario_runs_on_the_demo_twice_without_doubling_and_sends_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);

        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        app(CurrentHospital::class)->set($hospital->id);

        OwnerSummaryScenario::run($hospital);
        OwnerSummaryScenario::run($hospital);

        $owner = User::where('hospital_id', $hospital->id)->where('role', 'hospital_admin')->orderBy('id')->firstOrFail();
        $this->assertSame(['enabled' => true, 'channel' => 'email', 'at' => '07:30'], app(OwnerSummaryService::class)->preferences($owner));

        $rows = OwnerSummary::query()->where('user_id', $owner->id)->orderBy('summary_date')->get();
        $this->assertCount(OwnerSummaryScenario::DAYS, $rows);
        foreach ($rows as $row) {
            $this->assertSame('sent', $row->status);
            $this->assertStringContainsString('Kato Medical Centre', $row->body);
            $this->assertSame($hospital->id, $row->hospital_id);
        }
        $this->assertCount(0, iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }
}
