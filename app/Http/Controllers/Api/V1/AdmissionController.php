<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdmissionStatus;
use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\BedTransferRequest;
use App\Http\Requests\DischargeRequest;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Moving inpatients — the web's transfer and discharge dialogs, through the
 * same AdmissionService and requests (manage Admission). Admitting is an
 * order (POST /visits/{uuid}/orders, type admission).
 */
class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $service) {}

    /** Wards and their beds as they are now — occupancy is live, not a device's copy. */
    public function beds(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('ipd.view') || $request->user()->can('ipd.manage'), 403);

        $out = [];
        foreach (Ward::where('is_active', true)->with(['beds' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->orderBy('name')->get() as $ward) {
            $beds = [];
            foreach ($ward->beds as $bed) {
                $beds[] = ['id' => $bed->id, 'name' => $bed->name, 'status' => $bed->status->value, 'daily_charge' => (string) $bed->daily_charge];
            }
            $out[] = ['id' => $ward->id, 'name' => $ward->name, 'beds' => $beds];
        }

        return ApiResponse::success($out);
    }

    public function transfer(Request $request, Admission $admission): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate(BedTransferRequest::rulesFor());
        $bed = Bed::findOrFail($data['to_bed_id']);

        try {
            $this->service->transfer($admission, $bed, ($data['reason'] ?? null) ?: null, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['to_bed_id' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['bed' => $bed->name], 'Patient transferred to '.$bed->name.'.');
    }

    public function discharge(Request $request, Admission $admission): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate(DischargeRequest::rulesFor());

        try {
            $this->service->discharge($admission, AdmissionStatus::from($data['outcome']), ($data['discharge_notes'] ?? null) ?: null, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['outcome' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['status' => $data['outcome']], 'Admission closed.');
    }
}
