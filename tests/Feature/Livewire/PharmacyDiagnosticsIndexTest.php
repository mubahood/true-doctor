<?php

namespace Tests\Feature\Livewire;

use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PharmacyDiagnosticsIndexTest extends TestCase
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

    public function test_stock_index_searches_by_name(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Paracetamol 500mg']);
        StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Amoxicillin 250mg']);

        Livewire::test(\App\Livewire\Stock\Index::class)
            ->assertSee('Paracetamol 500mg')
            ->set('search', 'Amox')
            ->assertSee('Amoxicillin 250mg')
            ->assertDontSee('Paracetamol 500mg');
    }

    public function test_stock_index_forbidden_without_pharmacy_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\Stock\Index::class)->assertForbidden();
    }

    public function test_lab_orders_index_renders_and_gates(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Livewire::test(\App\Livewire\LabOrders\Index::class)->assertOk()->assertSee('Lab orders');
    }

    public function test_lab_orders_index_forbidden_without_lab_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\LabOrders\Index::class)->assertForbidden();
    }

    public function test_radiology_orders_index_renders_and_gates(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Livewire::test(\App\Livewire\RadiologyOrders\Index::class)->assertOk()->assertSee('Radiology orders');
    }

    public function test_radiology_orders_index_forbidden_without_radiology_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\RadiologyOrders\Index::class)->assertForbidden();
    }
}
