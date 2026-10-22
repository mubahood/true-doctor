<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyBackOfficeApiTest extends TestCase
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

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    private function item(array $o = []): StockItem
    {
        return StockItem::factory()->create(['hospital_id' => $this->h->id] + $o);
    }

    public function test_alerts_come_in_four_lanes_with_the_web_thresholds_and_a_category_filter(): void
    {
        $shelf = StockCategory::factory()->create(['hospital_id' => $this->h->id]);
        $this->item(['name' => 'Empty', 'current_quantity' => '0.00', 'reorder_level' => '10.00']);
        $this->item(['name' => 'Low', 'current_quantity' => '4.00', 'reorder_level' => '10.00', 'cost_price' => '5.00', 'stock_category_id' => $shelf->id]);
        $this->item(['name' => 'Gone off', 'current_quantity' => '3.00', 'reorder_level' => '1.00', 'expiry_date' => now()->subDays(2)->toDateString(), 'current_stock_value' => '6.00']);
        $this->item(['name' => 'Soon', 'current_quantity' => '3.00', 'reorder_level' => '1.00', 'expiry_date' => now()->addDays(20)->toDateString()]);
        $this->item(['name' => 'Far', 'current_quantity' => '3.00', 'reorder_level' => '1.00', 'expiry_date' => now()->addDays(200)->toDateString()]);
        $this->item(['name' => 'Retired', 'current_quantity' => '0.00', 'is_active' => false]);

        $this->as('pharmacist');
        $r = $this->getJson('/api/v1/stock-alerts')->assertOk();
        $this->assertSame(['Empty'], array_column($r->json('data.lanes.out'), 'name'));
        $this->assertSame(['Low'], array_column($r->json('data.lanes.low'), 'name'));
        $this->assertSame(['Gone off'], array_column($r->json('data.lanes.expired'), 'name'));
        $this->assertSame(['Soon'], array_column($r->json('data.lanes.expiring'), 'name'));
        $r->assertJsonPath('data.lanes.low.0.short_by', '6.00')
            ->assertJsonPath('data.lanes.low.0.shortfall_value', '30.00')
            ->assertJsonPath('data.lanes.expiring.0.days_left', 20)
            ->assertJsonPath('data.lanes.expiring.0.expiry_tone', 'warn')
            ->assertJsonPath('data.summary.out', 1)
            ->assertJsonPath('data.summary.value', '6.00')
            ->assertJsonPath('data.horizon_days', 90);

        $this->getJson('/api/v1/stock-alerts?category='.$shelf->id)->assertJsonCount(0, 'data.lanes.out')->assertJsonCount(1, 'data.lanes.low');
        $this->getJson('/api/v1/stock-alerts?lane=low')->assertJsonCount(1, 'data.lanes')->assertJsonPath('data.lanes.low.0.name', 'Low');
    }

    public function test_alerts_are_for_pharmacy_roles_and_one_hospital(): void
    {
        $this->item(['name' => 'Mine', 'current_quantity' => '0.00']);
        $other = Hospital::factory()->create();
        StockItem::factory()->create(['hospital_id' => $other->id, 'name' => 'Theirs', 'current_quantity' => '0.00']);

        $this->as('receptionist');
        $this->getJson('/api/v1/stock-alerts')->assertStatus(403);
        $this->getJson('/api/v1/stock-movements')->assertStatus(403);

        $this->as('pharmacist');
        $this->assertSame(['Mine'], array_column($this->getJson('/api/v1/stock-alerts')->json('data.lanes.out'), 'name'));
    }

    public function test_an_expiry_sweep_writes_off_each_line_on_its_own_and_only_expired_lines(): void
    {
        $a = $this->item(['name' => 'Old A', 'current_quantity' => '3.00', 'expiry_date' => now()->subDay()->toDateString(), 'cost_price' => '2.00']);
        $b = $this->item(['name' => 'Old B', 'current_quantity' => '5.00', 'expiry_date' => now()->subDay()->toDateString(), 'cost_price' => '2.00']);
        $fine = $this->item(['name' => 'Fine', 'current_quantity' => '5.00', 'expiry_date' => now()->addYear()->toDateString()]);
        app(StockService::class)->adjust($a, \App\Enums\StockMovementReason::OpeningStock, '3.00', null, 'open');
        app(StockService::class)->adjust($b, \App\Enums\StockMovementReason::OpeningStock, '5.00', null, 'open');

        $this->as('doctor');
        $this->postJson('/api/v1/stock-alerts/sweep', ['items' => [$a->uuid], 'reason' => 'expired', 'note' => 'Monthly sweep'])->assertStatus(403);

        $this->as('pharmacist');
        $this->postJson('/api/v1/stock-alerts/sweep', ['items' => [$a->uuid], 'reason' => 'expired'])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        $this->postJson('/api/v1/stock-alerts/sweep', ['items' => [$a->uuid], 'reason' => 'received', 'note' => 'nope'])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->postJson('/api/v1/stock-alerts/sweep', ['items' => [$fine->uuid], 'reason' => 'expired', 'note' => 'Monthly sweep'])->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);

        $this->postJson('/api/v1/stock-alerts/sweep', ['items' => [$a->uuid, $b->uuid], 'reason' => 'expired', 'note' => 'Monthly sweep'])
            ->assertOk()->assertJsonPath('data.done', 2);
        $this->assertSame('0.00', (string) $a->fresh()->current_quantity);
        $this->assertSame('0.00', (string) $b->fresh()->current_quantity);
        $this->assertSame(2, StockMovement::where('reason', 'expired')->count());
    }

    public function test_the_store_ledger_filters_by_item_direction_reason_dates_and_search(): void
    {
        $svc = app(StockService::class);
        $gloves = $this->item(['name' => 'Gloves']);
        $syringe = $this->item(['name' => 'Syringes']);
        $svc->receive($gloves, '20.00', '3.00', null, 'Delivery from Acme');
        $svc->adjust($gloves, \App\Enums\StockMovementReason::Damaged, '2.00', null, 'Dropped box');
        $svc->receive($syringe, '10.00', '1.00', null, null);

        $this->as('pharmacist');
        $all = $this->getJson('/api/v1/stock-movements')->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $all->assertJsonPath('data.0.item.name', 'Syringes')->assertJsonPath('data.0.direction', 'in')->assertJsonPath('meta.totals.in', '30.00')->assertJsonPath('meta.totals.out', '2.00');

        $this->assertSame(2, $this->getJson('/api/v1/stock-movements?item='.$gloves->uuid)->json('meta.total'));
        $out = $this->getJson('/api/v1/stock-movements?direction=out')->assertOk();
        $this->assertSame(1, $out->json('meta.total'));
        $out->assertJsonPath('data.0.reason', 'damaged')->assertJsonPath('data.0.balance_after', '18.00')->assertJsonPath('meta.totals.lost', '6.00');
        $this->assertSame(1, $this->getJson('/api/v1/stock-movements?reason=received&item='.$syringe->uuid)->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/stock-movements?q=Acme')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/stock-movements?q=syringe')->json('meta.total'));
        $this->assertSame(3, $this->getJson('/api/v1/stock-movements?from='.now()->toDateString().'&to='.now()->toDateString())->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/stock-movements?to='.now()->subDays(2)->toDateString())->json('meta.total'));
        $this->getJson('/api/v1/stock-movements?direction=sideways')->assertStatus(422);
        $this->getJson('/api/v1/stock-movements?sort=quantity&dir=asc')->assertJsonPath('data.0.quantity', '2.00');
    }

    public function test_the_ledger_never_shows_another_hospitals_movements(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = StockItem::factory()->create(['hospital_id' => $other->id, 'name' => 'Theirs']);
        app(StockService::class)->receive($theirs, '5.00', '1.00', null, null);
        app(CurrentHospital::class)->set($this->h->id);

        $this->as('pharmacist');
        $this->assertSame(0, $this->getJson('/api/v1/stock-movements')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/stock-movements?item='.$theirs->uuid)->json('meta.total'));
    }

    public function test_stock_items_are_created_edited_and_protected_like_the_web_form(): void
    {
        $cat = StockCategory::factory()->create(['hospital_id' => $this->h->id]);
        $this->as('doctor');
        $this->postJson('/api/v1/stock-items', ['name' => 'X'])->assertStatus(403);

        $this->as('pharmacist');
        $this->postJson('/api/v1/stock-items', ['name' => 'Amoxicillin'])->assertStatus(422)->assertJsonStructure(['errors' => ['unit', 'cost_price', 'reorder_level']])->assertJsonMissingPath('errors.sale_price');
        $this->postJson('/api/v1/stock-items', ['name' => 'A', 'unit' => 'caps', 'cost_price' => 1, 'sale_price' => 2, 'reorder_level' => 5, 'stock_category_id' => 99999])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['stock_category_id']]);

        $created = $this->postJson('/api/v1/stock-items', [
            'name' => 'Amoxicillin 500mg', 'unit' => 'caps', 'cost_price' => '120.5', 'sale_price' => 200, 'reorder_level' => 50,
            'stock_category_id' => $cat->id, 'expiry_date' => '2030-01-31', 'sku' => '',
            'current_quantity' => 9999,
        ])->assertCreated()->assertJsonPath('data.name', 'Amoxicillin 500mg')->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.current_quantity', '0.00')->assertJsonPath('data.expiry_date', '2030-01-31')->assertJsonPath('data.sku', null);
        $uuid = $created->json('data.uuid');
        $this->assertNotEmpty($uuid);

        // A partial update changes only what was sent; is_active is not reset by leaving it out.
        $this->patchJson("/api/v1/stock-items/{$uuid}", ['sale_price' => 250])->assertOk()
            ->assertJsonPath('data.sale_price', '250.00')->assertJsonPath('data.cost_price', '120.50')->assertJsonPath('data.is_active', true);
        $this->putJson("/api/v1/stock-items/{$uuid}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/v1/stock-items/{$uuid}", ['reorder_level' => -1])->assertStatus(422);

        // No history: it can go. With history: it cannot.
        $this->deleteJson("/api/v1/stock-items/{$uuid}")->assertOk();
        $this->getJson("/api/v1/stock-items/{$uuid}")->assertStatus(404);

        $used = $this->item(['name' => 'Used']);
        app(StockService::class)->receive($used, '5.00', '1.00', null, null);
        $this->deleteJson("/api/v1/stock-items/{$used->uuid}")->assertStatus(422)
            ->assertJsonPath('message', 'This item has stock movements and cannot be deleted — deactivate it instead.');
        $this->assertNotNull($used->fresh());

        $this->as('doctor');
        $this->putJson("/api/v1/stock-items/{$used->uuid}", ['sale_price' => 1])->assertStatus(403);
        $this->deleteJson("/api/v1/stock-items/{$used->uuid}")->assertStatus(403);
    }

    public function test_another_hospitals_stock_item_cannot_be_touched(): void
    {
        $other = Hospital::factory()->create();
        $theirs = StockItem::factory()->create(['hospital_id' => $other->id]);

        $this->as('hospital_admin');
        $this->patchJson("/api/v1/stock-items/{$theirs->uuid}", ['sale_price' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/stock-items/{$theirs->uuid}")->assertStatus(404);
        $this->assertNotNull(StockItem::withoutGlobalScopes()->find($theirs->id));
    }

    public function test_stock_categories_list_with_shelf_figures_and_refuse_deletion_while_occupied(): void
    {
        $meds = StockCategory::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Medicines']);
        $empty = StockCategory::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Stationery']);
        $this->item(['stock_category_id' => $meds->id, 'current_quantity' => '2.00', 'reorder_level' => '5.00', 'current_stock_value' => '40.00']);

        $this->as('pharmacist');
        $list = $this->getJson('/api/v1/stock-categories')->assertOk();
        $list->assertJsonPath('data.0.name', 'Medicines')->assertJsonPath('data.0.items_count', 1)->assertJsonPath('data.0.low_count', 1)
            ->assertJsonPath('data.0.on_hand', '2.00')->assertJsonPath('data.0.stock_value', '40.00')->assertJsonPath('meta.store.low', 1)
            ->assertJsonPath('meta.module_label', 'Pharmacy');
        $this->assertCount(1, $this->getJson('/api/v1/stock-categories?attention=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/stock-categories?q=Station')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/stock-categories?status=active')->json('data'));

        $this->postJson('/api/v1/stock-categories', ['name' => 'Medicines', 'unit' => 'box'])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $new = $this->postJson('/api/v1/stock-categories', ['name' => 'Dental', 'unit' => 'box'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/stock-categories/{$new}", ['description' => 'Burs and cements'])->assertOk()->assertJsonPath('data.description', 'Burs and cements')->assertJsonPath('data.unit', 'box');
        $this->deleteJson("/api/v1/stock-categories/{$meds->id}")->assertStatus(422)->assertJsonPath('message', 'This category has stock items — reassign them first.');
        $this->deleteJson("/api/v1/stock-categories/{$empty->id}")->assertOk();
        $this->getJson("/api/v1/stock-categories/{$meds->id}")->assertOk()->assertJsonPath('data.items.0.name', $meds->items()->first()->name);

        // An archived name comes back rather than colliding.
        $this->postJson('/api/v1/stock-categories', ['name' => 'Stationery', 'unit' => 'pack'])->assertCreated()->assertJsonPath('message', 'Category restored from the archive.');

        $this->as('doctor');
        $this->getJson('/api/v1/stock-categories')->assertStatus(403);
    }

    public function test_the_price_list_handles_ranges_and_is_gated_like_the_page(): void
    {
        Service::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Consultation', 'code' => 'CON1', 'price' => '20000.00']);
        $other = Hospital::factory()->create();
        Service::factory()->create(['hospital_id' => $other->id, 'name' => 'Their service']);

        // Anyone in the back office can read it; only services.manage writes.
        $this->as('receptionist');
        $this->getJson('/api/v1/services')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/services', ['name' => 'Scaling', 'price' => 1000])->assertStatus(403);

        $this->as('accountant');
        $this->getJson('/api/v1/services?q=CON1')->assertJsonPath('data.0.name', 'Consultation')->assertJsonPath('data.0.has_range', false);
        $this->postJson('/api/v1/services', ['name' => 'Difficult extraction', 'price' => 50000, 'max_price' => 40000])->assertStatus(422)->assertJsonStructure(['errors' => ['max_price']]);
        $id = $this->postJson('/api/v1/services', ['name' => 'Difficult extraction', 'price' => 50000, 'max_price' => 80000, 'category' => 'Dental'])
            ->assertCreated()->assertJsonPath('data.has_range', true)->assertJsonPath('data.max_price', '80000.00')->assertJsonPath('data.is_active', true)->json('data.id');
        $this->postJson('/api/v1/services', ['name' => 'Difficult extraction', 'price' => 1])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->postJson('/api/v1/services', ['name' => 'Bad ledger', 'price' => 1, 'ledger_account_id' => 99999])->assertStatus(422)->assertJsonStructure(['errors' => ['ledger_account_id']]);

        // The range is checked against the stored price when only one end moves.
        $this->patchJson("/api/v1/services/{$id}", ['price' => 90000])->assertStatus(422)->assertJsonStructure(['errors' => ['max_price']]);
        $this->patchJson("/api/v1/services/{$id}", ['max_price' => null])->assertOk()->assertJsonPath('data.has_range', false);
        $this->putJson("/api/v1/services/{$id}", ['tax_exempt' => true])->assertOk()->assertJsonPath('data.tax_exempt', true)->assertJsonPath('data.price', '50000.00');

        $this->getJson("/api/v1/services/{$id}")->assertOk()->assertJsonPath('data.usage.times', 0);
        $this->assertContains('Dental', $this->getJson('/api/v1/services')->json('meta.categories'));
        $this->assertIsArray($this->getJson('/api/v1/services')->json('meta.income_accounts'));

        $theirs = Service::withoutGlobalScopes()->where('name', 'Their service')->first();
        $this->getJson("/api/v1/services/{$theirs->id}")->assertStatus(404);
        $this->patchJson("/api/v1/services/{$theirs->id}", ['price' => 1])->assertStatus(404);

        $this->deleteJson("/api/v1/services/{$id}")->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/services')->json('meta.total'));
    }

    public function test_lab_tests_follow_lab_view_and_lab_manage(): void
    {
        LabTest::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Malaria RDT']);
        LabTest::factory()->create(['hospital_id' => Hospital::factory()->create()->id, 'name' => 'Theirs']);

        $this->as('receptionist');
        $this->getJson('/api/v1/lab-tests')->assertStatus(403);

        $this->as('doctor'); // lab.view, not lab.manage
        $this->getJson('/api/v1/lab-tests?q=malaria')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/lab-tests', ['name' => 'FBC', 'price' => 5000])->assertStatus(403);

        $this->as('lab_technician');
        $this->getJson('/api/v1/lab-tests')->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/lab-tests', ['name' => 'Malaria RDT', 'price' => 5000])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->postJson('/api/v1/lab-tests', ['name' => 'FBC', 'price' => -1])->assertStatus(422)->assertJsonStructure(['errors' => ['price']]);
        $id = $this->postJson('/api/v1/lab-tests', ['name' => 'FBC', 'price' => 5000, 'specimen' => 'Blood', 'reference_range' => '4-11', 'unit' => ''])
            ->assertCreated()->assertJsonPath('data.price', '5000.00')->assertJsonPath('data.unit', null)->json('data.id');
        $this->patchJson("/api/v1/lab-tests/{$id}", ['price' => 6000])->assertOk()->assertJsonPath('data.price', '6000.00')->assertJsonPath('data.specimen', 'Blood');
        $this->getJson("/api/v1/lab-tests/{$id}")->assertJsonPath('data.usage.earned', '0.00');
        $this->deleteJson("/api/v1/lab-tests/{$id}")->assertOk();
        $this->getJson("/api/v1/lab-tests/{$id}")->assertStatus(404);
        $this->postJson('/api/v1/lab-tests', ['name' => 'FBC', 'price' => 7000])->assertCreated()->assertJsonPath('message', 'Lab test restored from the archive.');
    }

    public function test_radiology_studies_follow_radiology_view_and_radiology_manage(): void
    {
        RadiologyStudy::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Chest X-ray']);
        RadiologyStudy::factory()->create(['hospital_id' => Hospital::factory()->create()->id, 'name' => 'Theirs']);

        $this->as('pharmacist');
        $this->getJson('/api/v1/radiology-studies')->assertStatus(403);

        $this->as('doctor');
        $this->getJson('/api/v1/radiology-studies')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/radiology-studies', ['name' => 'Pelvis', 'price' => 1])->assertStatus(403);

        $this->as('radiologist');
        $this->postJson('/api/v1/radiology-studies', ['name' => 'Chest X-ray', 'price' => 1])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $id = $this->postJson('/api/v1/radiology-studies', ['name' => 'Abdominal ultrasound', 'modality' => 'US', 'body_part' => 'Abdomen', 'price' => 45000])
            ->assertCreated()->assertJsonPath('data.modality', 'US')->json('data.id');
        $this->patchJson("/api/v1/radiology-studies/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.price', '45000.00');
        $this->deleteJson("/api/v1/radiology-studies/{$id}")->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/radiology-studies')->json('meta.total'));
    }

    public function test_the_store_is_called_what_the_practice_calls_it(): void
    {
        $this->h->update(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $this->as('pharmacist');
        $this->getJson('/api/v1/stock-items')->assertOk()->assertJsonPath('meta.module_label', 'Drugs inventory');
        $this->getJson('/api/v1/stock-alerts')->assertJsonPath('data.module_label', 'Drugs inventory');
        $this->getJson('/api/v1/stock-movements')->assertJsonPath('meta.module_label', 'Drugs inventory');
        $this->getJson('/api/v1/stock-categories')->assertJsonPath('meta.module_label', 'Drugs inventory');
    }
}
