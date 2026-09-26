<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DoseRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoseAdministerRequest;
use App\Http\Requests\PrescriptionRequest;
use App\Models\DoseItemRecord;
use App\Models\Prescription;
use App\Models\Visit;
use App\Services\PrescriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Prescriptions on a visit and the doses they schedule — the web's
 * prescriptions panel, through the same PrescriptionRequest, service and
 * policy (prescribe / administer).
 */
class PrescriptionController extends Controller
{
    public function __construct(private readonly PrescriptionService $service) {}

    public function index(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $prescriptions = $visit->prescriptions()
            ->with(['prescriber', 'doseItems.records.administeredBy'])
            ->latest()->get();

        $out = [];
        foreach ($prescriptions as $p) {
            $out[] = $this->shape($p);
        }

        return ApiResponse::success($out);
    }

    /** @return array<string,mixed> */
    private function shape(Prescription $p): array
    {
        $items = [];
        foreach ($p->doseItems as $d) {
            $doses = [];
            // Every scheduled dose, in order: the round a nurse works down.
            foreach ($d->records->sortBy(fn (DoseItemRecord $r) => $r->scheduled_date->toDateString().' '.$r->slot->value) as $r) {
                $doses[] = [
                    'id' => $r->id,
                    'date' => $r->scheduled_date->toDateString(),
                    'slot' => $r->slot->value,
                    'status' => $r->status->value,
                    'administered_at' => $r->administered_at?->toIso8601String(),
                    'administered_by' => $r->administeredBy?->name,
                    'note' => $r->note,
                ];
            }
            $items[] = [
                'id' => $d->id,
                'drug_name' => $d->drug_name,
                'dosage' => $d->dosage,
                'slots' => array_values((array) $d->slots),
                'days' => (int) $d->days,
                'start_date' => $d->start_date->toDateString(),
                'instructions' => $d->instructions,
                'doses' => $doses,
            ];
        }

        return [
            'uuid' => $p->uuid,
            'prescribed_by' => $p->prescriber?->name,
            'notes' => $p->notes,
            'created_at' => $p->created_at?->toIso8601String(),
            'items' => $items,
        ];
    }

    public function store(PrescriptionRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('prescribe', Prescription::class);

        $data = $request->validated();
        $items = array_map(fn (array $row) => [
            'drug_name' => (string) $row['drug_name'],
            'dosage' => $row['dosage'] ?? null,
            'slots' => array_values($row['slots']),
            'days' => (int) $row['days'],
            'start_date' => $row['start_date'] ?? null,
            'instructions' => $row['instructions'] ?? null,
        ], array_values($data['items']));

        $this->service->prescribe($visit, $items, $data['notes'] ?? null, $request->user()->id);

        return ApiResponse::success(['prescribed' => true], 'Prescription added.', 201);
    }

    /** A nurse marks one scheduled dose given or missed. */
    public function markDose(DoseAdministerRequest $request, DoseItemRecord $record): JsonResponse
    {
        $this->authorize('administer', Prescription::class);

        $data = $request->validated();
        $record = $this->service->markDose($record, DoseRecordStatus::from($data['status']), $request->user()->id, $data['note'] ?? null);

        return ApiResponse::success(['id' => $record->id, 'status' => $record->status->value], 'Dose updated.');
    }
}
