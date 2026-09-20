<?php

namespace App\Services;

use App\Enums\LabOrderStatus;
use App\Enums\OrderType;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lab ordering + results. Ordering snapshots each test (name/price/range) onto
 * the order AND bills it on the visit (BillingService) — one transaction,
 * so an order and its charges never diverge. Results are entered per item;
 * status moves through the LabOrderStatus machine (transition()). Reuses the
 * InvalidVisitTransitionException family message via a lab-specific guard.
 */
class LabService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * @param  list<int>  $testIds
     */
    public function order(Visit $visit, array $testIds, ?string $notes = null, ?int $by = null): LabOrder
    {
        if ($testIds === []) {
            throw new RuntimeException('Select at least one test.');
        }

        return DB::transaction(function () use ($visit, $testIds, $notes, $by) {
            $order = LabOrder::create([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'ordered_by' => $by,
                'status' => LabOrderStatus::Ordered,
                'clinical_notes' => $notes,
            ]);

            // The work, and the money it will cost, now hang together.
            $labWork = app(OrderService::class)->place($visit, OrderType::Lab, 'Lab tests', [
                'notes' => $notes,
                'subject' => $order,
            ], $by);

            foreach ($testIds as $testId) {
                /** @var LabTest $test */
                $test = LabTest::whereKey($testId)->firstOrFail();

                $order->items()->create([
                    // The id a bench device reports its result against. Minted
                    // here rather than left to the device, because the LINE is
                    // the server's — a doctor ordered this test — and only the
                    // value in it comes from the bench. Without it a device
                    // has no way to name which test it is reporting.
                    'uuid' => (string) Str::uuid(),
                    'lab_test_id' => $test->id,
                    'name' => $test->name,
                    'unit' => $test->unit,
                    'reference_range' => $test->reference_range,
                    'price' => $test->price,
                ]);

                $this->billing->addItem($labWork, [
                    'name' => $test->name,
                    'unit_price' => (string) $test->price,
                    'quantity' => 1,
                ], $by);
            }

            return $order;
        });
    }

    public function recordResult(LabOrderItem $item, array $data, ?int $by = null): LabOrderItem
    {
        $item->fill([
            'result_value' => $data['result_value'] ?? null,
            'result_flag' => $data['result_flag'] ?? null,
            'result_notes' => $data['result_notes'] ?? null,
            'resulted_at' => Carbon::now(),
            'resulted_by' => $by,
        ])->save();

        return $item;
    }

    public function transition(LabOrder $order, LabOrderStatus $to, ?int $by = null): LabOrder
    {
        return DB::transaction(function () use ($order, $to) {
            /** @var LabOrder $locked */
            $locked = LabOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($to)) {
                throw new RuntimeException("Cannot move a lab order from {$locked->status->label()} to {$to->label()}.");
            }

            $locked->status = $to;
            if ($to === LabOrderStatus::Completed) {
                $locked->completed_at = Carbon::now();
                $locked->save();

                // Notify the ordering doctor that results are ready.
                $locked->loadMissing('orderedBy');
                if ($locked->orderedBy !== null) {
                    $locked->orderedBy->notify(new \App\Notifications\LabResultReady($locked));
                }

                return $locked;
            }
            $locked->save();

            return $locked;
        });
    }
}
