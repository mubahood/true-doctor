<?php

namespace Tests\Feature\Livewire;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Models\Ward;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the AJAX modal create/edit path on representative catalogue
 * modules: checkboxes + money (Services), and enum + relation selects (Beds).
 * The remaining generated catalogues share the identical modal scaffold.
 */
class CatalogModalTest extends TestCase
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

    public function test_service_modal_creates_with_checkboxes_and_price(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\Services\Index::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('name', 'X-Ray Chest')
            ->set('price', '45000')
            ->set('tax_exempt', true)
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $s = Service::where('name', 'X-Ray Chest')->first();
        $this->assertNotNull($s);
        $this->assertTrue((bool) $s->tax_exempt);
        $this->assertEquals(45000, (float) $s->price);
    }

    public function test_service_modal_edits_existing(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $s = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Old Service']);

        Livewire::test(\App\Livewire\Services\Index::class)
            ->call('edit', $s->id)
            ->assertSet('name', 'Old Service')
            ->set('name', 'Renamed Service')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Service', $s->fresh()->name);
    }

    public function test_bed_modal_creates_with_enum_and_relation(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('create')
            ->set('ward_id', $ward->id)
            ->set('name', 'Bed-7')
            ->set('daily_charge', '30000')
            ->set('status', \App\Enums\BedStatus::Available->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $bed = Bed::where('name', 'Bed-7')->first();
        $this->assertNotNull($bed);
        $this->assertSame($ward->id, $bed->ward_id);
        $this->assertSame(\App\Enums\BedStatus::Available, $bed->status);
    }

    public function test_bed_modal_requires_ward_and_status(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('create')
            ->set('name', 'Bed-X')
            ->set('daily_charge', '10000')
            ->call('save')
            ->assertHasErrors(['ward_id', 'status'])
            ->assertSet('showForm', true);
    }

    public function test_stock_category_modal_creates_and_refuses_to_delete_a_used_category(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\StockCategories\Index::class)
            ->call('create')
            ->set('name', 'Antibiotics')
            ->set('unit', 'tablet')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $category = \App\Models\StockCategory::where('name', 'Antibiotics')->firstOrFail();
        \App\Models\StockItem::factory()->create(['hospital_id' => $h->id, 'stock_category_id' => $category->id]);

        Livewire::test(\App\Livewire\StockCategories\Index::class)
            ->call('delete', $category->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull(\App\Models\StockCategory::find($category->id));
    }

    public function test_lab_test_and_radiology_study_modals_create_with_price(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\LabTests\Index::class)
            ->call('create')
            ->set('name', 'Full Blood Count')
            ->set('code', 'fbc')
            ->set('specimen', 'Blood')
            ->set('price', '15000')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $test = \App\Models\LabTest::where('name', 'Full Blood Count')->firstOrFail();
        $this->assertEquals(15000, (float) $test->price);

        // Names are unique per hospital.
        Livewire::test(\App\Livewire\LabTests\Index::class)
            ->call('create')->set('name', 'Full Blood Count')->set('price', '1')
            ->call('save')->assertHasErrors(['name']);

        Livewire::test(\App\Livewire\RadiologyStudies\Index::class)
            ->call('create')
            ->set('name', 'Chest X-Ray')
            ->set('modality', 'X-Ray')
            ->set('body_part', 'Chest')
            ->set('price', '60000')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $study = \App\Models\RadiologyStudy::where('name', 'Chest X-Ray')->firstOrFail();
        $this->assertEquals(60000, (float) $study->price);
        $this->assertSame('X-Ray', $study->modality);
    }
}
