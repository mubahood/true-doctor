<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DosageRequest;
use App\Models\DoseItem;
use App\Models\Prescription;
use App\Models\Visit;
use App\Services\PrescriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dosage on a visit (docs/pharmacy.md, "Dosage"; docs/api/dosage.md): the
 * web's Dosage panel for the app, through the same DosageRequest, service and
 * policy. The rows are prescription lines; nothing is billed here.
 */
class DosageController extends Controller
{
    public function __construct(private readonly PrescriptionService $service) {}

    public function index(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('viewClinical', $visit);

        return ApiResponse::success($this->payload($visit));
    }

    public function store(DosageRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('prescribe', Prescription::class);

        $rows = array_map(fn (array $r) => [
            'stock_item_id' => (int) $r['stock_item_id'],
            'dose' => (string) $r['dose'],
            'frequency' => (string) $r['frequency'],
            'days' => (int) $r['days'],
            'note' => $r['note'] ?? null,
        ], array_values($request->validated()['items']));

        try {
            $this->service->writeDosage($visit, $rows, $request->user()->id);
        } catch (\DomainException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->payload($visit), 'Dosage saved.', 201);
    }

    public function destroy(Request $request, Visit $visit, string $dose): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('prescribe', Prescription::class);

        // The line must be on this visit (and, through the global scope, this tenant).
        $item = DoseItem::query()->where('uuid', $dose)
            ->whereHas('prescription', fn ($q) => $q->where('visit_id', $visit->id))
            ->firstOrFail();

        try {
            $this->service->removeDose($item);
        } catch (\DomainException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->payload($visit), 'Medicine removed.');
    }

    /** @return array<string,mixed> */
    private function payload(Visit $visit): array
    {
        $items = DoseItem::query()
            ->with('prescription.prescriber:id,name')
            ->whereHas('prescription', fn ($q) => $q->where('visit_id', $visit->id))
            ->oldest('id')->get();

        $out = [];
        foreach ($items as $d) {
            $out[] = [
                'uuid' => $d->uuid,
                'prescription_uuid' => $d->prescription?->uuid,
                'stock_item_id' => $d->stock_item_id,
                'medicine' => $d->drug_name,
                'dose' => $d->dosage,
                'frequency' => $d->howOften(),
                'days' => (int) $d->days,
                'note' => $d->instructions,
                'prescribed_by' => $d->prescription?->prescriber?->name,
                'created_at' => $d->created_at?->toIso8601String(),
            ];
        }

        return ['items' => $out, 'frequencies' => DoseItem::FREQUENCIES];
    }
}
