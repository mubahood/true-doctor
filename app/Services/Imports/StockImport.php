<?php

namespace App\Services\Imports;

use App\Enums\StockMovementReason;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\CatalogueStore;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stock items with what is on the shelf today. The item is created empty and
 * its quantity goes on through StockService as OPENING stock: the clinic
 * already owned it, so in the books it is the clinic's own equity, not a debt
 * to a supplier (docs/finance.md), exactly as the starter-data import does.
 *
 * Updating an item already in stock changes its details and prices, never its
 * quantity: a count that differs is a stock count, done on the stock page,
 * where it is booked as a gain or a loss.
 */
class StockImport extends ImportHandler
{
    public function __construct(private readonly StockService $stock) {}

    public function fields(): array
    {
        return [
            new ImportField('name', 'Item', 'The name as it should appear when dispensed or sold.', 'Lidocaine 2% cartridge', true,
                ['item', 'item name', 'name', 'drug', 'drug name', 'product', 'product name', 'description', 'medicine', 'stock item', 'items']),
            new ImportField('category', 'Category', 'The group it belongs to. A new one is created if it does not exist.', 'Anaesthetics', false,
                ['group', 'type', 'class', 'stock category']),
            new ImportField('unit', 'Unit', 'What one of it is: tablet, bottle, cartridge, box.', 'cartridge', false,
                ['units', 'uom', 'unit of measure', 'pack', 'form', 'unit of issue']),
            new ImportField('quantity', 'Quantity', 'How many are on the shelf today, in that unit.', '200', false,
                ['qty', 'stock', 'balance', 'in stock', 'quantity in stock', 'on hand', 'closing stock', 'current stock', 'stock level', 'available']),
            new ImportField('cost_price', 'Cost', 'What you pay for one.', '1500', false,
                ['cost price', 'buying price', 'unit cost', 'purchase price', 'bp', 'cost per unit']),
            new ImportField('sale_price', 'Selling price', 'What a patient pays for one.', '3000', false,
                ['price', 'sale price', 'sp', 'retail price', 'unit price', 'selling']),
            new ImportField('reorder_level', 'Reorder level', 'Warn when the shelf gets down to this many.', '50', false,
                ['reorder', 'minimum', 'min stock', 'reorder point', 'min', 'minimum stock']),
            new ImportField('batch_no', 'Batch', 'The batch or lot number, if you track it.', 'LD2207', false, ['batch no', 'batch number', 'lot', 'lot no']),
            new ImportField('expiry_date', 'Expiry date', 'Day first: 31/12/2027.', '31/12/2027', false,
                ['expiry', 'exp', 'exp date', 'expires', 'expiry dd/mm/yyyy', 'best before']),
            new ImportField('sku', 'Code', 'Your own code or barcode, if you use one.', '', false, ['sku', 'item code', 'barcode', 'product code']),
        ];
    }

    public function sheetKey(array $v): ?string
    {
        $name = mb_strtolower(trim($v['name'] ?? ''));

        return $name === '' ? null : $name.'|'.mb_strtolower(trim($v['batch_no'] ?? ''));
    }

    public function check(array $v): array
    {
        $problems = [];
        $name = trim($v['name'] ?? '');
        if ($name === '') {
            $problems[] = 'There is no item name.';
        } elseif (mb_strlen($name) > 120) {
            $problems[] = 'The name is longer than 120 letters.';
        }

        foreach (['quantity' => 'Quantity', 'cost_price' => 'Cost', 'sale_price' => 'Selling price', 'reorder_level' => 'Reorder level'] as $key => $label) {
            $amount = $this->money($v[$key] ?? '');
            if ($amount === false) {
                $problems[] = $label.' "'.$v[$key].'" is not a number.';
            } elseif (is_string($amount) && bccomp($amount, '0', 2) < 0) {
                $problems[] = $label.' is below zero.';
            }
        }

        if ($this->date($v['expiry_date'] ?? '') === false) {
            $problems[] = 'Expiry date "'.$v['expiry_date'].'" is not a date we can read. Write it like 31/12/2027.';
        }
        foreach (['unit' => [32, 'unit'], 'batch_no' => [64, 'batch'], 'sku' => [48, 'code'], 'category' => [100, 'category']] as $key => [$max, $label]) {
            if (mb_strlen($v[$key] ?? '') > $max) {
                $problems[] = 'The '.$label.' is longer than '.$max.' characters.';
            }
        }

        $match = null;
        if ($problems === []) {
            $hits = StockItem::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->get();
            $batch = trim($v['batch_no'] ?? '');
            $hit = $hits->first(fn (StockItem $i) => (string) $i->batch_no === $batch) ?? $hits->first();
            if ($hit !== null) {
                $match = ['id' => $hit->id, 'reason' => 'Same name', 'label' => $hit->name.' ('.rtrim(rtrim((string) $hit->current_quantity, '0'), '.').' '.$hit->unit.' in stock)'];
            }
        }

        return ['problems' => $problems, 'match' => $match];
    }

    public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array
    {
        $details = [];
        if (($v['category'] ?? '') !== '') {
            $details['stock_category_id'] = $this->category($v['category'], $v['unit'] ?? '')->id;
        }
        foreach (['unit', 'batch_no', 'sku'] as $key) {
            if (($v[$key] ?? '') !== '') {
                $details[$key] = $v[$key];
            }
        }
        foreach (['cost_price', 'sale_price', 'reorder_level'] as $key) {
            $amount = $this->money($v[$key] ?? '');
            if (is_string($amount)) {
                $details[$key] = $amount;
            }
        }
        $expiry = $this->date($v['expiry_date'] ?? '');
        if (is_string($expiry)) {
            $details['expiry_date'] = $expiry;
        }

        if ($decision === 'update' && $matchId !== null) {
            $item = StockItem::query()->find($matchId) ?? throw new RuntimeException('The stock item it matched is no longer there.');
            // Prices and details only; the quantity is the stock count's business.
            $item->update($details);
            if (isset($details['cost_price'])) {
                $item->update(['current_stock_value' => bcmul((string) $item->current_quantity, (string) $item->cost_price, 2)]);
            }

            return ['status' => ImportRow::UPDATED, 'type' => StockItem::class, 'id' => $item->id, 'message' => 'Details and prices updated; the quantity was left as it is.'];
        }

        [$item, $state] = CatalogueStore::persist(StockItem::class, null, $details + [
            'uuid' => (string) Str::uuid(),
            'name' => trim($v['name']),
            'unit' => 'unit',
            'original_quantity' => '0.00',
            'current_quantity' => '0.00',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
            'current_stock_value' => '0.00',
            'reorder_level' => '0.00',
            'is_active' => true,
        ], ['batch_no', 'sku'], ['name', 'batch_no']);
        /** @var StockItem $item */
        $extra = ['restored' => $state === 'restored'];

        $quantity = $this->money($v['quantity'] ?? '');
        if (is_string($quantity) && bccomp($quantity, '0', 2) > 0) {
            $movement = $this->stock->adjust($item, StockMovementReason::OpeningStock, $quantity, $by, 'Opening stock (imported)');
            $extra['movement_id'] = $movement->id;
            $extra['quantity'] = $quantity;
        }

        return ['status' => ImportRow::ADDED, 'type' => StockItem::class, 'id' => $item->id, 'extra' => $extra];
    }

    public function undoBlocker(ImportRow $row): ?string
    {
        $item = StockItem::query()->find($row->record_id);
        if ($item === null) {
            return null;
        }

        $opening = (int) ($row->extra['movement_id'] ?? 0);

        return match (true) {
            StockMovement::query()->where('stock_item_id', $item->id)->whereKeyNot($opening)->exists() => 'Stock has moved since.',
            $row->updated_at !== null && $item->updated_at !== null && $item->updated_at->gt($row->updated_at) => 'Was changed since.',
            default => null,
        };
    }

    public function undo(ImportRow $row, ?int $by): void
    {
        $item = StockItem::query()->find($row->record_id);
        if ($item === null) {
            return;
        }

        // Back out exactly what the import put on the shelf, against opening
        // equity as it came in, so the books return to where they were.
        $quantity = (string) ($row->extra['quantity'] ?? '0');
        if (bccomp($quantity, '0', 2) > 0 && bccomp((string) $item->current_quantity, '0', 2) > 0) {
            $back = bccomp($quantity, (string) $item->current_quantity, 2) > 0 ? (string) $item->current_quantity : $quantity;
            $this->stock->adjust($item, StockMovementReason::OpeningUndone, $back, $by, 'Import undone');
        }

        $item->refresh()->delete();
    }

    private function category(string $name, string $unit): StockCategory
    {
        $name = mb_substr(trim($name), 0, 100);

        return StockCategory::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? StockCategory::query()->create(['name' => $name, 'unit' => $unit !== '' ? mb_substr($unit, 0, 32) : 'unit', 'is_active' => true]);
    }
}
