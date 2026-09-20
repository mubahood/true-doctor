<?php

namespace Tests\Feature\Livewire;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\User;
use App\Models\Ward;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InpatientIndexTest extends TestCase
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

    public function test_wards_index_searches_and_deletes(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Maternity Wing']);
        $drop = Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Surgical Wing']);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->assertSee('Maternity Wing')
            ->set('search', 'Maternity')
            ->assertSee('Maternity Wing')
            ->assertDontSee('Surgical Wing')
            ->set('search', '')
            ->call('delete', $drop->id)
            ->assertHasNoErrors();

        $this->assertNull(Ward::find($drop->id));
    }

    public function test_beds_index_searches(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);
        Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'BedAlpha']);
        Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'BedBravo']);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->set('search', 'BedAlpha')
            ->assertSee('BedAlpha')
            ->assertDontSee('BedBravo');
    }

    public function test_ward_modal_creates_edits_and_enforces_a_unique_name(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->call('create')->assertSet('showForm', true)
            ->set('name', 'Paediatrics')->set('description', 'Children')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $ward = Ward::where('name', 'Paediatrics')->firstOrFail();
        $this->assertSame($h->id, $ward->hospital_id);
        $this->assertTrue($ward->is_active);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->call('edit', $ward->id)->assertSet('name', 'Paediatrics')
            ->set('name', 'Paediatric wing')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Paediatric wing', $ward->fresh()->name);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->call('create')->set('name', 'Paediatric wing')
            ->call('save')->assertHasErrors(['name']);
    }

    public function test_a_ward_that_still_has_beds_cannot_be_deleted(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);
        Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id]);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->call('delete', $ward->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull(Ward::find($ward->id));
    }

    public function test_an_occupied_bed_cannot_be_deleted_or_have_its_status_flipped(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);
        $bed = Bed::factory()->create([
            'hospital_id' => $h->id, 'ward_id' => $ward->id,
            'name' => 'Bed-Occupied', 'status' => \App\Enums\BedStatus::Occupied->value,
        ]);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('delete', $bed->id)
            ->assertDispatched('toast', type: 'error');
        $this->assertNotNull(Bed::find($bed->id));

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('edit', $bed->id)
            ->set('status', \App\Enums\BedStatus::Available->value)
            ->set('name', 'Bed-Renamed')
            ->call('save')->assertHasNoErrors();

        $bed->refresh();
        $this->assertSame(\App\Enums\BedStatus::Occupied, $bed->status);
        $this->assertSame('Bed-Renamed', $bed->name);
    }

    public function test_beds_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $wardB = Ward::factory()->create(['hospital_id' => $b->id]);
        $bedB = Bed::factory()->create(['hospital_id' => $b->id, 'ward_id' => $wardB->id, 'name' => 'HiddenBed']);
        $this->actingAdmin($a);

        Livewire::test(\App\Livewire\Beds\Index::class)->assertDontSee('HiddenBed');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(\App\Livewire\Beds\Index::class)->call('edit', $bedB->id);
    }

    public function test_admissions_index_renders_and_gates(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Livewire::test(\App\Livewire\Admissions\Index::class)->assertOk()->assertSee('Admissions');
    }

    public function test_inpatient_indexes_forbidden_without_ipd_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\Wards\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\Beds\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\Admissions\Index::class)->assertForbidden();
    }
}
