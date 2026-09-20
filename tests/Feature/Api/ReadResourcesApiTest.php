<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadResourcesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_stock_items_index_and_low_filter(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $ok = StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'PlentyMed', 'current_quantity' => '100', 'reorder_level' => '10']);
        $low = StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'LowMed', 'current_quantity' => '2', 'reorder_level' => '10']);
        Sanctum::actingAs($this->staff($h, 'pharmacist'));

        $this->getJson('/api/v1/stock-items')->assertOk()->assertJsonPath('meta.total', 2);
        $res = $this->getJson('/api/v1/stock-items?filter=low')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('LowMed', $res->json('data.0.name'));
        $this->getJson("/api/v1/stock-items/{$low->uuid}")->assertOk()->assertJsonPath('data.is_low_stock', true);
    }

    public function test_stock_items_forbidden_for_non_pharmacy_role(): void
    {
        $h = Hospital::factory()->create();
        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $this->getJson('/api/v1/stock-items')->assertStatus(403);
    }

    public function test_invoices_index_and_show(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);
        Sanctum::actingAs($this->staff($h, 'accountant'));

        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/invoices/{$invoice->uuid}")->assertOk()
            ->assertJsonPath('data.total', '100.00')->assertJsonPath('data.invoice_no', $invoice->invoice_no);
    }

    public function test_read_resources_are_tenant_scoped(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        app(CurrentHospital::class)->set($b->id);
        $itemB = StockItem::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretB']);
        Sanctum::actingAs($this->staff($a, 'pharmacist'));

        $this->getJson('/api/v1/stock-items')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/stock-items/{$itemB->uuid}")->assertStatus(404);
    }
}
