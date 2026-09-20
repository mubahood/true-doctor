<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\RadiologyOrderStatus;
use App\Models\RadiologyOrder;
use App\Models\RadiologyStudy;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Radiology ordering + reporting. Ordering snapshots each study onto the order
 * AND bills it on the visit (BillingService) in one transaction. The
 * report is a per-order narrative (findings + impression). Status moves through
 * the RadiologyOrderStatus machine.
 */
class RadiologyService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * @param  list<int>  $studyIds
     */
    public function order(Visit $visit, array $studyIds, ?string $notes = null, ?int $by = null): RadiologyOrder
    {
        if ($studyIds === []) {
            throw new RuntimeException('Select at least one study.');
        }

        return DB::transaction(function () use ($visit, $studyIds, $notes, $by) {
            $order = RadiologyOrder::create([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'ordered_by' => $by,
                'status' => RadiologyOrderStatus::Ordered,
                'clinical_notes' => $notes,
            ]);

            $imagingWork = app(OrderService::class)->place($visit, OrderType::Imaging, 'Imaging', [
                'notes' => $notes,
                'subject' => $order,
            ], $by);

            foreach ($studyIds as $studyId) {
                /** @var RadiologyStudy $study */
                $study = RadiologyStudy::whereKey($studyId)->firstOrFail();

                $order->items()->create([
                    'radiology_study_id' => $study->id,
                    'name' => $study->name,
                    'modality' => $study->modality,
                    'price' => $study->price,
                ]);

                $this->billing->addItem($imagingWork, [
                    'name' => $study->name,
                    'unit_price' => (string) $study->price,
                    'quantity' => 1,
                ], $by);
            }

            return $order;
        });
    }

    public function recordReport(RadiologyOrder $order, ?string $findings, ?string $impression, ?int $by = null): RadiologyOrder
    {
        $order->fill([
            'findings' => $findings,
            'impression' => $impression,
            'reported_by' => $by,
            'reported_at' => Carbon::now(),
        ])->save();

        return $order;
    }

    public function transition(RadiologyOrder $order, RadiologyOrderStatus $to, ?int $by = null): RadiologyOrder
    {
        return DB::transaction(function () use ($order, $to) {
            /** @var RadiologyOrder $locked */
            $locked = RadiologyOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($to)) {
                throw new RuntimeException("Cannot move a radiology order from {$locked->status->label()} to {$to->label()}.");
            }

            $locked->status = $to;
            if ($to === RadiologyOrderStatus::Reported && $locked->reported_at === null) {
                $locked->reported_at = Carbon::now();
            }
            $locked->save();

            return $locked;
        });
    }
}
