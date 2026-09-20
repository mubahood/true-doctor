<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\AppointmentTransitionRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * API appointments — same AppointmentService (booking + conflict detection +
 * state machine) and AppointmentPolicy as the panel. Domain conflicts (slot
 * unavailable, illegal transition) surface as 422 in the envelope, not 500.
 */
class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $appointments = Appointment::with(['patient', 'doctor'])
            ->when($request->filled('date'), fn ($q) => $q->forDate($request->date('date')->format('Y-m-d')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('doctor'), fn ($q) => $q->where('doctor_user_id', $request->integer('doctor')))
            ->orderBy('scheduled_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($appointments, AppointmentResource::collection($appointments->items()));
    }

    public function store(AppointmentRequest $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        try {
            $appointment = $this->service->book($request->validated(), $request->user()->id);
        } catch (Throwable $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new AppointmentResource($appointment->load(['patient', 'doctor'])), 'Appointment booked.', 201);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return ApiResponse::success(new AppointmentResource($appointment->load(['patient', 'doctor'])));
    }

    public function transition(AppointmentTransitionRequest $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        try {
            $this->service->transition($appointment, $request->targetStatus(), $request->user()->id, $request->validated('note'));
        } catch (Throwable $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new AppointmentResource($appointment->fresh()->load(['patient', 'doctor'])), 'Appointment updated.');
    }
}
