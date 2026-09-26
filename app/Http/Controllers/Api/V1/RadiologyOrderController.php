<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\RadiologyOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RadiologyReportRequest;
use App\Http\Resources\RadiologyOrderResource;
use App\Models\RadiologyOrder;
use App\Services\RadiologyService;
use App\Support\ApiResponse;
use App\Support\RadiologyBench;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The radiology worklist (radiology.view to read, radiology.report to work):
 * the web worklist's filters and tally, moving an order along, and the
 * report — optionally signed off in the same press, as on the web.
 */
class RadiologyOrderController extends Controller
{
    private const WITH = ['patient', 'items', 'visit', 'orderedBy', 'reportedBy'];

    public function __construct(private readonly RadiologyService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('radiology.view'), 403);

        $orders = RadiologyBench::filter(
            RadiologyOrder::with(self::WITH),
            (string) $request->query('status', ''),
            $request->boolean('outstanding'),
            (string) $request->query('q', ''),
        )
            ->when($request->boolean('outstanding'), fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('id'))
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($orders, RadiologyOrderResource::collection($orders->items()), [
            'tally' => RadiologyBench::tally(),
            'old_hours' => RadiologyBench::OLD_HOURS,
        ]);
    }

    public function show(Request $request, RadiologyOrder $radiologyOrder): JsonResponse
    {
        abort_unless($request->user()->can('radiology.view'), 403);

        return ApiResponse::success(new RadiologyOrderResource($radiologyOrder->load(self::WITH)));
    }

    public function transition(Request $request, RadiologyOrder $radiologyOrder): JsonResponse
    {
        abort_unless($request->user()->can('radiology.report'), 403);

        $to = RadiologyOrderStatus::from($request->validate(['status' => ['required', Rule::enum(RadiologyOrderStatus::class)]])['status']);

        try {
            $this->service->transition($radiologyOrder, $to, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new RadiologyOrderResource($radiologyOrder->fresh(self::WITH)), 'Moved to '.$to->label().'.');
    }

    /** Write the report; `sign_off` also marks it Reported, in one press. */
    public function report(RadiologyReportRequest $request, RadiologyOrder $radiologyOrder): JsonResponse
    {
        abort_unless($request->user()->can('radiology.report'), 403);

        $data = $request->validated();
        $this->service->recordReport($radiologyOrder, $data['findings'] ?? null, $data['impression'] ?? null, $request->user()->id);

        if ($request->boolean('sign_off')) {
            try {
                $this->service->transition($radiologyOrder->fresh(), RadiologyOrderStatus::Reported, $request->user()->id);
            } catch (RuntimeException $e) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
            }

            return ApiResponse::success(new RadiologyOrderResource($radiologyOrder->fresh(self::WITH)), 'Reported and signed off.');
        }

        return ApiResponse::success(new RadiologyOrderResource($radiologyOrder->fresh(self::WITH)), 'Report saved.');
    }
}
