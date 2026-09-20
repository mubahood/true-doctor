<?php

namespace Tests\Feature\Livewire;

use App\Models\Department;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke + behaviour coverage for the generated org/config catalogue tables
 * (Departments, Services, Financial years shown here as representatives; the
 * remaining catalogues share the identical generated shape and are also
 * exercised by their existing controller-era HTTP feature tests, which now
 * render through the Livewire components at the same URLs).
 */
class CatalogIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function stripped(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_departments_search_and_delete(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Cardiology Unit']);
        $drop = Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Oncology Unit']);

        Livewire::test(\App\Livewire\Departments\Index::class)
            ->assertSee('Cardiology Unit')
            ->set('search', 'Cardio')
            ->assertSee('Cardiology Unit')
            ->assertDontSee('Oncology Unit')
            ->set('search', '')
            ->call('delete', $drop->id)
            ->assertHasNoErrors();

        $this->assertNull(Department::find($drop->id));
    }

    public function test_services_search(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Service::factory()->create(['hospital_id' => $h->id, 'name' => 'General Visit']);
        Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Minor Surgery']);

        Livewire::test(\App\Livewire\Services\Index::class)
            ->set('search', 'Visit')
            ->assertSee('General Visit')
            ->assertDontSee('Minor Surgery');
    }

    public function test_financial_years_close_and_reopen(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $fy = FinancialYear::create([
            'hospital_id' => $h->id,
            'name' => 'FY 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
        ]);

        Livewire::test(\App\Livewire\FinancialYears\Index::class)
            ->call('close', $fy->id)
            ->assertHasNoErrors();
        $this->assertFalse($fy->fresh()->isOpen());

        Livewire::test(\App\Livewire\FinancialYears\Index::class)
            ->call('reopen', $fy->id)
            ->assertHasNoErrors();
        $this->assertTrue($fy->fresh()->isOpen());
    }

    public function test_catalog_indexes_forbidden_without_permission(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\Departments\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\Services\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\LabTests\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\RadiologyStudies\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\StockCategories\Index::class)->assertForbidden();
    }
}
