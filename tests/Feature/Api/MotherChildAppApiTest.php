<?php

namespace Tests\Feature\Api;

use App\Livewire\Patients\Tabs\Growth;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\GrowthService;
use App\Support\CurrentHospital;
use App\Support\MotherChild\WhoGrowth;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the app's maternity and child health screens read beyond the web's
 * (docs/api/mother-child.md): the WHO weight-for-age lines the growth chart is
 * drawn on, from the same GrowthService answer the web's chart is drawn from,
 * and the due list's tally and dose tones.
 */
class MotherChildAppApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
    }

    public function test_the_growth_answer_carries_the_who_lines_to_a_little_past_the_last_weighing(): void
    {
        $child = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subMonths(14)->toDateString()]);
        Sanctum::actingAs($this->nurse);
        $this->postJson("/api/v1/patients/{$child->uuid}/growth", ['weight' => 9.1, 'measured_on' => now()->toDateString()])->assertCreated();

        $ref = $this->getJson("/api/v1/patients/{$child->uuid}/growth")->assertOk()->json('data.reference');

        $this->assertSame(18, $ref['months'], 'fourteen months, three more, to the next half year');
        $this->assertSame([-3, -2, 0, 2], array_column($ref['lines'], 'z'));
        $median = $ref['lines'][2]['points'];
        $this->assertCount(19, $median, 'one point a month from birth');
        $this->assertSame([0, round((float) WhoGrowth::weightAt(0, 0, false), 2)], $median[0]);
        $this->assertGreaterThan($ref['lines'][3]['points'][18][1], $ref['kg_max'], 'the +3 line fits under the top');
    }

    public function test_no_lines_without_a_date_of_birth_or_a_sex(): void
    {
        $child = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => null, 'dob' => now()->subMonths(6)->toDateString()]);
        Sanctum::actingAs($this->nurse);

        $this->getJson("/api/v1/patients/{$child->uuid}/growth")->assertOk()->assertJsonPath('data.reference', null);
    }

    public function test_the_due_list_carries_the_dose_tone_and_the_overdue_tally(): void
    {
        // Ten weeks old: birth doses overdue, six-week doses overdue or due.
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'male', 'dob' => now()->subWeeks(10)->toDateString(), 'first_name' => 'Baby']);
        Sanctum::actingAs($this->nurse);

        $res = $this->getJson('/api/v1/immunisations/due?per_page=1')->assertOk();
        $doses = $res->json('data.0.doses');
        $this->assertNotEmpty($doses);
        foreach ($doses as $dose) {
            $this->assertSame(['due' => 'info', 'overdue' => 'danger'][$dose['status']], $dose['tone']);
        }
        $this->assertSame($res->json('data.0.overdue'), $res->json('meta.overdue_total'));
        $this->assertGreaterThan(0, $res->json('meta.overdue_total'));

        // A date that does not parse is today, never a server error.
        $this->getJson('/api/v1/immunisations/due?on=not-a-date')->assertOk()->assertJsonPath('meta.on', now()->toDateString());
    }

    public function test_the_web_chart_is_drawn_on_the_same_lines(): void
    {
        $child = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'male', 'dob' => now()->subMonths(30)->toDateString()]);
        app(GrowthService::class)->record($child, ['weight' => 12.4], null, $this->nurse);

        $component = Livewire::withoutLazyLoading()->actingAs($this->nurse)->test(Growth::class, ['patientId' => $child->id]);
        $chart = $component->instance()->chart();
        $ref = app(GrowthService::class)->weightReference($child, app(GrowthService::class)->points($child));

        $this->assertNotNull($chart);
        $this->assertSame(36, $ref['months']);
        $this->assertCount(4, $chart['lines']);
        $this->assertCount(37, explode(' ', $chart['lines'][0]['points']), 'a point a month to the same end');
        $this->assertCount(1, $chart['dots']);
        $component->assertSee('Weight-for-age');
    }
}
