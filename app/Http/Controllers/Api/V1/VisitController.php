<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\VisitClinicalRequest;
use App\Http\Requests\VisitRequest;
use App\Http\Requests\VisitTransitionRequest;
use App\Http\Requests\VisitVitalsRequest;
use App\Http\Resources\VisitResource;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * API visits — same VisitService (numbering, state machine, vitals
 * with BMI) and VisitPolicy as the panel. Each ability (open / vitals /
 * diagnose / advance) is authorized identically (C13).
 */
class VisitController extends Controller
{
    public function __construct(private readonly VisitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visit::class);

        $visits = Visit::with(['patient', 'doctor'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($visits, VisitResource::collection($visits->items()));
    }

    public function store(VisitRequest $request): JsonResponse
    {
        $this->authorize('create', Visit::class);

        $visit = $this->service->open($request->validated(), $request->user()->id);

        return ApiResponse::success(new VisitResource($visit->load(['patient', 'doctor'])), 'Visit opened.', 201);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        return ApiResponse::success(new VisitResource($visit->load(['patient', 'doctor'])));
    }

    public function vitals(VisitVitalsRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('recordVitals', $visit);

        $this->service->recordVitals($visit, $request->validated());

        return ApiResponse::success(new VisitResource($visit->fresh()->load(['patient', 'doctor'])), 'Vitals recorded.');
    }

    public function clinical(VisitClinicalRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('diagnose', $visit);

        $this->service->updateClinical($visit, $request->validated());

        return ApiResponse::success(new VisitResource($visit->fresh()->load(['patient', 'doctor'])), 'Clinical notes saved.');
    }

    public function transition(VisitTransitionRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('manage', $visit);

        try {
            $this->service->advance($visit, $request->user()->id, $request->validated('note'));
        } catch (Throwable $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new VisitResource($visit->fresh()->load(['patient', 'doctor'])), 'Visit advanced.');
    }
}
