<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\DispenseQueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** The pharmacist's queue (pharmacy.dispense, as dispensing itself) and a patient's last prescription (visits.view, as reading prescriptions). */
class DispenseQueueController extends Controller
{
    use PagesAndDeltas;

    public function __construct(private readonly DispenseQueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('pharmacy.dispense'), 403);
        $request->validate(['status' => ['nullable', 'in:pending']]);

        $rows = $this->queue->pending();
        $per = $this->perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $per)->values()->all(), $rows->count(), $per, $page);

        return ApiResponse::paginated($paginator, $paginator->items(), [
            'oldest_wait_minutes' => $rows->max('waited_minutes'),
            'window_days' => DispenseQueueService::WINDOW_DAYS,
        ]);
    }

    public function last(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->can('visits.view'), 403);
        $data = $request->validate(['visit' => ['nullable', 'string', 'max:64']]);

        $exclude = filled($data['visit'] ?? null) ? Visit::where('uuid', $data['visit'])->value('id') : null;

        return ApiResponse::success(['prescription' => $this->queue->lastPrescription($patient, $exclude)]);
    }
}
