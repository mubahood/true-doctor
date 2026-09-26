<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\LabOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LabOrderResource;
use App\Models\LabOrder;
use App\Services\LabService;
use App\Support\ApiResponse;
use App\Support\LabBench;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The lab bench (lab.view to read, lab.process to work): the web worklist's
 * filters and tally (App\Support\LabBench), and moving an order along
 * through LabService. Results themselves are written through offline sync
 * (entity `lab_items`), so a bench with no signal can still report.
 */
class LabOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lab.view'), 403);

        $orders = LabBench::filter(
            LabOrder::with(['patient', 'items', 'visit', 'orderedBy']),
            (string) $request->query('status', ''),
            $request->boolean('outstanding'),
            (string) $request->query('q', ''),
        )
            // The oldest outstanding work first when that is what was asked
            // for — it is what a bench works down; otherwise newest first.
            ->when($request->boolean('outstanding'), fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('id'))
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($orders, LabOrderResource::collection($orders->items()), [
            'tally' => LabBench::tally(),
            'old_hours' => LabBench::OLD_HOURS,
        ]);
    }

    public function show(Request $request, LabOrder $labOrder): JsonResponse
    {
        abort_unless($request->user()->can('lab.view'), 403);

        return ApiResponse::success(new LabOrderResource($labOrder->load(['patient', 'items', 'visit', 'orderedBy'])));
    }

    /** Collected, processing, completed or cancelled — where LabOrderStatus allows it. */
    public function transition(Request $request, LabOrder $labOrder, LabService $lab): JsonResponse
    {
        abort_unless($request->user()->can('lab.process'), 403);

        $to = LabOrderStatus::from($request->validate([
            'status' => ['required', Rule::enum(LabOrderStatus::class)],
        ])['status']);

        try {
            $lab->transition($labOrder, $to, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(
            new LabOrderResource($labOrder->fresh(['patient', 'items', 'visit', 'orderedBy'])),
            'Moved to '.$to->label().'.',
        );
    }
}
