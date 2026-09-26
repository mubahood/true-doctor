<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\AppointmentTransitionRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Support\ApiResponse;
use App\Support\DoctorSlots;
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

    /** The doctors a booking may be made with. */
    public function doctors(Request $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);
        $q = trim((string) $request->query('q', ''));

        return ApiResponse::success(\App\Models\User::currentHospital()->where('role', 'doctor')
            ->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
            ->orderBy('name')->limit(50)->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values());
    }

    /**
     * When a doctor can be booked: the fortnight's days (which they sit), and
     * for a chosen day the free times at this length — the web dialog's own
     * answer (App\Support\DoctorSlots), so an offered time books.
     */
    public function availability(Request $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);
        $data = $request->validate([
            'doctor' => ['required', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:480'],
            'ignore' => ['nullable', 'string'],
        ]);
        $doctor = (int) $data['doctor'];
        $length = (int) ($data['duration'] ?? 30);
        $ignore = filled($data['ignore'] ?? null) ? Appointment::where('uuid', $data['ignore'])->value('id') : null;

        return ApiResponse::success([
            'days' => DoctorSlots::days($doctor),
            'times' => isset($data['date']) ? DoctorSlots::open($doctor, $data['date'], $length, $ignore) : [],
            'note' => DoctorSlots::note($doctor, $data['date'] ?? null, $length),
        ]);
    }

    /** Move it — the new time is checked as a booking is (its own slot stays free to it). */
    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);
        $rules = AppointmentRequest::rulesFor();
        $data = $request->validate([
            'scheduled_at' => $rules['scheduled_at'],
            'duration_minutes' => $rules['duration_minutes'],
            'room_id' => $rules['room_id'],
        ]);

        try {
            $this->service->reschedule($appointment, (string) $data['scheduled_at'], (int) $data['duration_minutes'], isset($data['room_id']) ? (int) $data['room_id'] : null, $request->user()->id);
        } catch (\RuntimeException $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['scheduled_at' => [$e->getMessage()]]);
        }

        return ApiResponse::success(new AppointmentResource($appointment->fresh(['patient', 'doctor'])), 'Appointment moved.');
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return ApiResponse::success(new AppointmentResource($appointment->load(['patient', 'doctor'])));
    }

    /**
     * Record what was done at an appointment — the web's "Record outcome"
     * (ActsOnAppointments::saveOutcome), same rule for the report, same
     * service. Completing it is the default, as on the web.
     */
    public function outcome(\Illuminate\Http\Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        $data = $request->validate([
            'report' => ['required', 'string', 'min:3', 'max:5000'],
            'complete' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->service->recordOutcome($appointment, (string) $data['report'], [], $request->user()->id, (bool) ($data['complete'] ?? true));
        } catch (Throwable $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success(new AppointmentResource($appointment->fresh()->load(['patient', 'doctor', 'room'])), 'Outcome recorded.');
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
