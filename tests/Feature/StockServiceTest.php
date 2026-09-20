<?php

namespace Tests\Feature;

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStockException;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function item(array $o = []): StockItem
    {
        return StockItem::factory()->create(array_merge(['hospital_id' => $this->hospital->id], $o));
    }

    public function test_receive_increases_quantity_and_revalues(): void
    {
        $item = $this->item(['cost_price' => '2.50']);

        $mv = $this->stock->receive($item, '40', '2.50', null, 'PO-1');

        $item->refresh();
        $this->assertSame('40.00', (string) $item->current_quantity);
        $this->assertSame('100.00', (string) $item->current_stock_value); // 40 × 2.50
        $this->assertSame('40.00', (string) $mv->balance_after);
        $this->assertSame(StockMovementReason::Received, $mv->reason);
    }

    public function test_dispense_decreases_quantity(): void
    {
        $item = $this->item(['cost_price' => '2.00']);
        $this->stock->receive($item, '30', '2.00');

        $this->stock->dispenseOut($item->fresh(), '12');

        $item->refresh();
        $this->assertSame('18.00', (string) $item->current_quantity);
        $this->assertSame('36.00', (string) $item->current_stock_value); // 18 × 2.00
    }

    public function test_cannot_drive_stock_negative(): void
    {
        $item = $this->item();
        $this->stock->receive($item, '5', '2.00');

        try {
            $this->stock->dispenseOut($item->fresh(), '9');
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            // expected
        }

        $this->assertSame('5.00', (string) $item->fresh()->current_quantity);
        $this->assertSame(1, $item->movements()->count()); // only the receive
    }

    public function test_ledger_is_append_only_with_running_balance(): void
    {
        $item = $this->item();
        $this->stock->receive($item, '10', '2.00');
        $this->stock->receive($item->fresh(), '5', '2.00');
        $this->stock->dispenseOut($item->fresh(), '4');

        // reorder(): the relation is newest-first for the screens that read it,
        // so a ledger asserted in the order it was written must say so.
        $balances = $item->movements()->reorder('id')->pluck('balance_after')->map(fn ($b) => (string) $b)->all();
        $this->assertSame(['10.00', '15.00', '11.00'], $balances);
    }

    public function test_wastage_adjustment_reduces_stock(): void
    {
        $item = $this->item();
        $this->stock->receive($item, '20', '2.00');

        $this->stock->adjust($item->fresh(), StockMovementReason::Wastage, '3');

        $this->assertSame('17.00', (string) $item->fresh()->current_quantity);
    }

    public function test_low_stock_and_expiry_scopes(): void
    {
        $low = $this->item(['name' => 'Low', 'current_quantity' => '5.00', 'reorder_level' => '10.00']);
        $ok = $this->item(['name' => 'Fine', 'current_quantity' => '50.00', 'reorder_level' => '10.00']);
        $exp = $this->item(['name' => 'OldBatch', 'current_quantity' => '50.00', 'reorder_level' => '10.00', 'expiry_date' => '2020-01-01']);

        $this->assertTrue($low->fresh()->isLowStock());
        $this->assertFalse($ok->fresh()->isLowStock());
        $this->assertTrue($exp->fresh()->isExpired());

        $this->assertEqualsCanonicalizing(['Low'], StockItem::lowStock()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['OldBatch'], StockItem::expiringBefore('2021-01-01')->pluck('name')->all());
    }
}
