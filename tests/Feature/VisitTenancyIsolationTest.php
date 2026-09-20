<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** §2.1 — hospital A can neither see nor act on hospital B's visits. */
class VisitTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_a_cannot_see_or_act_on_bs_visit(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Zeb', 'last_name' => 'FromB']);
        $consultB = Visit::factory()->create(['hospital_id' => $b->id, 'patient_id' => $patientB->id]);
        $adminA = $this->admin($a);

        $this->actingAs($adminA)->get('/admin/visits')->assertOk()->assertDontSee('FromB');

        // The workspace is route-model-bound: B's visit simply does not exist here.
        $this->actingAs($adminA)->get("/admin/visits/{$consultB->uuid}")->assertNotFound();

        $this->assertSame('pending', Visit::withoutGlobalScopes()->find($consultB->id)->status->value);
    }

    /**
     * Every panel of the workspace re-resolves the visit through the tenant
     * scope on mount, so a hand-crafted foreign id is a 404 in each of them.
     *
     * @return list<array{0: class-string}>
     */
    public static function panelProvider(): array
    {
        return [
            [\App\Livewire\Visits\Panels\Vitals::class],
            [\App\Livewire\Visits\Panels\Clinical::class],
            [\App\Livewire\Visits\Panels\Charges::class],
            [\App\Livewire\Visits\Panels\LabOrders::class],
            [\App\Livewire\Visits\Panels\RadiologyOrders::class],
            [\App\Livewire\Visits\Panels\Dispense::class],
            [\App\Livewire\Visits\Panels\Prescriptions::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('panelProvider')]
    public function test_no_panel_can_be_mounted_on_another_hospitals_visit(string $panel): void
    {
        Livewire::withoutLazyLoading();

        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $consultB = Visit::factory()->create(['hospital_id' => $b->id]);

        $this->actingAs($this->admin($a));
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test($panel, ['visitId' => $consultB->id]);
    }

    /**
     * Defence in depth: even if route-model binding were bypassed and the
     * workspace handed B's model, every later request re-reads the visit
     * through the tenant scope and finds nothing.
     */
    public function test_the_workspace_re_resolves_the_visit_through_the_tenant_scope(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $consultB = Visit::factory()->create(['hospital_id' => $b->id]);

        $this->actingAs($this->admin($a));
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(\App\Livewire\Visits\Show::class, ['visit' => $consultB]);
    }

    public function test_cannot_open_visit_for_another_hospitals_patient(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        $this->actingAs($this->admin($a));
        app(CurrentHospital::class)->set($a->id);

        Livewire::test(\App\Livewire\Visits\Index::class)
            ->call('create')
            ->set('patient_id', $patientB->id)
            ->call('save')
            ->assertHasErrors('patient_id');
        $this->assertDatabaseCount('visits', 0);
    }
}
