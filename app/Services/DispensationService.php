<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Dispensation;
use App\Models\StockItem;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dispenses drugs against a visit: for each line it deducts stock through
 * StockService (append-only ledger, negative-stock guard) AND adds a billable
 * line to the visit through BillingService — all in ONE transaction, so a
 * dispensation, its stock movements and its charges are always consistent. If any
 * item lacks stock, InsufficientStockException rolls the whole dispensation back.
 */
class DispensationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BillingService $billing,
    ) {}

    /**
     * @param  list<array{stock_item_id:int,quantity:string}>  $lines
     */
    public function dispense(Visit $visit, array $lines, ?string $note = null, ?int $by = null, ?int $prescriptionId = null): Dispensation
    {
        if ($lines === []) {
            throw new RuntimeException('Nothing to dispense.');
        }

        return DB::transaction(function () use ($visit, $lines, $note, $by, $prescriptionId) {
            $dispensation = Dispensation::create([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'prescription_id' => $prescriptionId,
                'patient_id' => $visit->patient_id,
                'note' => $note,
                'dispensed_by' => $by,
            ]);

            // One order for the dispensing, carrying its own charges — so a
            // cancelled dispensation takes its money with it.
            $pharmacyWork = app(OrderService::class)->place($visit, OrderType::Pharmacy, 'Dispensing', [
                'notes' => $note,
                'subject' => $dispensation,
            ], $by);

            foreach ($lines as $line) {
                /** @var StockItem $item */
                $item = StockItem::whereKey($line['stock_item_id'])->firstOrFail();
                $qty = \App\Support\HospitalSettings::decimal($line['quantity'], 2);
                $unitPrice = (string) $item->sale_price;
                $lineTotal = bcmul($unitPrice, $qty, 2);

                $dispensationItem = $dispensation->items()->create([
                    'stock_item_id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);

                // Deduct stock (append-only movement; may throw InsufficientStock → rollback).
                $this->stock->dispenseOut($item, $qty, [
                    'source' => $dispensationItem,
                    'note' => "Dispensed to {$visit->patient?->full_name}",
                ], $by);

                // Bill the drug on the pharmacy order, saying plainly what it
                // was and how much of it. The quantity used to be folded into
                // the description and charged as one, because the column was a
                // whole number; now that it is decimal the line can hold the
                // real figure, which is what makes it reversible and
                // reportable. The money is the same either way.
                $this->billing->addItem($pharmacyWork, [
                    'stock_item_id' => $item->id,
                    'name' => $item->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                ], $by);
            }

            return $dispensation;
        });
    }
}
