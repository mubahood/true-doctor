<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_dispensing_bills_takes_off_the_shelf_and_names_a_shortfall(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->h->id])->id, 'status' => 'ongoing']);
        $amox = StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Amoxicillin', 'current_quantity' => '10.00', 'sale_price' => '500.00']);
        $pcm = StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Paracetamol', 'current_quantity' => '2.00', 'sale_price' => '100.00']);

        Sanctum::actingAs($this->staff('doctor'));
        $this->postJson("/api/v1/visits/{$visit->uuid}/dispensations", ['items' => [['stock_item_id' => $amox->id, 'quantity' => 1]]])->assertStatus(403);

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->postJson("/api/v1/visits/{$visit->uuid}/dispensations", ['items' => [
            ['stock_item_id' => $amox->id, 'quantity' => 3], ['stock_item_id' => $pcm->id, 'quantity' => 5],
        ]])->assertStatus(422)->assertJsonStructure(['errors' => ['items.1.quantity']]);
        $this->assertSame('10.00', (string) $amox->fresh()->current_quantity, 'a refusal takes nothing');

        $this->postJson("/api/v1/visits/{$visit->uuid}/dispensations", ['items' => [['stock_item_id' => $amox->id, 'quantity' => 3]]])
            ->assertCreated()->assertJsonPath('message', 'Dispensed and billed.');
        $this->assertSame('7.00', (string) $amox->fresh()->current_quantity);
        $this->getJson("/api/v1/visits/{$visit->uuid}/dispensations")->assertJsonPath('data.0.items.0.name', 'Amoxicillin');
    }

    public function test_the_store_receives_adjusts_and_writes_off_with_a_reason(): void
    {
        $item = StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Gloves', 'current_quantity' => '5.00']);
        Sanctum::actingAs($this->staff('pharmacist'));

        $this->postJson("/api/v1/stock-items/{$item->uuid}/receive", ['quantity' => 20, 'unit_cost' => 150])
            ->assertOk()->assertJsonPath('data.current_quantity', '25.00');
        $this->postJson("/api/v1/stock-items/{$item->uuid}/write-off", ['quantity' => 2, 'reason' => 'expired'])
            ->assertStatus(422)->assertJsonPath('errors.note.0', 'Say why it is being written off — this is the record somebody audits.');
        $this->postJson("/api/v1/stock-items/{$item->uuid}/write-off", ['quantity' => 100, 'reason' => 'expired', 'note' => 'Box found expired'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
        $this->postJson("/api/v1/stock-items/{$item->uuid}/write-off", ['quantity' => 2, 'reason' => 'expired', 'note' => 'Box found expired'])
            ->assertOk()->assertJsonPath('data.current_quantity', '23.00');

        $ledger = $this->getJson("/api/v1/stock-items/{$item->uuid}/movements")->assertOk();
        $ledger->assertJsonPath('data.0.reason', 'expired')->assertJsonPath('data.0.note', 'Box found expired')->assertJsonPath('data.1.reason', 'received');
        $this->assertContains('expired', $ledger->json('meta.loss_reasons'));
    }
}
