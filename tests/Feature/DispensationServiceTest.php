<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\Visit;
use App\Services\DispensationService;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispensationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DispensationService $dispenser;

    private StockService $stock;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispenser = app(DispensationService::class);
        $this->stock = app(StockService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function visit(): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
    }

    private function item(string $qty, string $sale): StockItem
    {
        $item = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'sale_price' => $sale]);
        $this->stock->receive($item, $qty, '1.00');

        return $item->fresh();
    }

    public function test_dispensing_deducts_stock_and_bills_the_drug(): void
    {
        $c = $this->visit();
        $drug = $this->item('100', '0.50');

        $dispensation = $this->dispenser->dispense($c, [
            ['stock_item_id' => $drug->id, 'quantity' => '20'],
        ], null, null);

        // Stock deducted 100 → 80.
        $this->assertSame('80.00', (string) $drug->fresh()->current_quantity);
        // Dispensation line snapshot.
        $this->assertSame('10.00', (string) $dispensation->items()->first()->line_total); // 20 × 0.50
        // A billable line was added to the visit.
        $this->assertSame(1, $c->orderItems()->count());
        $this->assertSame('10.00', (string) $c->orderItems()->first()->line_total);
    }

    public function test_insufficient_stock_rolls_back_the_whole_dispensation(): void
    {
        $c = $this->visit();
        $ok = $this->item('100', '1.00');
        $short = $this->item('3', '1.00');

        try {
            $this->dispenser->dispense($c, [
                ['stock_item_id' => $ok->id, 'quantity' => '10'],
                ['stock_item_id' => $short->id, 'quantity' => '9'], // only 3 in stock
            ], null, null);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            // expected
        }

        // Nothing persisted: no dispensation, first item's stock untouched, no bill lines.
        $this->assertSame(0, \App\Models\Dispensation::count());
        $this->assertSame('100.00', (string) $ok->fresh()->current_quantity);
        $this->assertSame(0, $c->orderItems()->count());
    }
}
