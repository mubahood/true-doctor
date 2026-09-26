<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidVisitTransitionException;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VisitClinicalRequest;
use App\Http\Requests\VisitIntakeRequest;
use App\Http\Requests\VisitRequest;
use App\Http\Requests\VisitTransitionRequest;
use App\Http\Requests\VisitVitalsRequest;
use App\Http\Resources\VisitResource;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\ApiResponse;
use App\Support\VisitPhrases;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The web's visit list: the same flattened state filter, the same stage
     * filter and the same search (Visit::inState / matching), newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visit::class);

        $status = (string) $request->query('status', '');
        $stage = VisitStage::tryFrom((string) $request->query('stage', ''));

        $visits = Visit::with(['patient', 'doctor'])
            // What the row's gate needs, counted in the one statement.
            ->withCount(['orders as open_orders_count' => fn (Builder $q) => $q->whereIn(
                'status', [OrderStatus::Pending->value, OrderStatus::InProgress->value],
            )])
            ->withCount(['invoices as live_invoices_count' => fn (Builder $q) => $q->where(
                'status', '!=', InvoiceStatus::Void->value,
            )])
            // `completed` was the raw status before the flattened filter; it
            // still means every finished visit.
            ->when($status === VisitStatus::Completed->value,
                fn (Builder $q) => $q->where('status', VisitStatus::Completed->value),
                fn (Builder $q) => $q->inState($status))
            ->when($request->boolean('open'), fn (Builder $q) => $q->where('status', '!=', VisitStatus::Completed->value))
            ->when($stage, fn (Builder $q) => $q->where('stage', $stage->value))
            ->when($request->filled('patient'), fn (Builder $q) => $q->whereHas(
                'patient', fn (Builder $p) => $p->where('uuid', (string) $request->query('patient')),
            ))
            ->matching((string) $request->query('q', ''))
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($visits, VisitResource::collection($visits->items()));
    }

    /** Open a visit for a registered patient, with whatever was taken at the desk. */
    public function store(VisitRequest $request): JsonResponse
    {
        $this->authorize('create', Visit::class);

        $data = $request->validated();

        $visit = $this->service->open(
            array_intersect_key($data, array_flip(['patient_id', 'appointment_id', 'doctor_user_id', 'department_id', 'reason', 'complaints', 'diagnosis'])),
            $request->user()->id,
        );
        $visit = $this->service->recordWhatWasTaken($visit, $data, $request->user()->id);

        return ApiResponse::success($this->one($visit), 'Visit opened — '.$visit->visit_no.'.', 201);
    }

    /**
     * Register a brand-new patient and open their visit — the web dialog's
     * "New patient" tab, through the same request and the same service.
     */
    public function intake(VisitIntakeRequest $request): JsonResponse
    {
        $this->authorize('create', Visit::class);
        $this->authorize('create', Patient::class);

        $data = $request->validated();

        try {
            $visit = $this->service->intake(
                $request->patientData(),
                $request->visitData() + ['diagnosis' => $data['diagnosis'] ?? null],
                $request->user()->id,
            );
        } catch (PlanLimitExceededException $e) {
            return ApiResponse::error(ApiErrorCode::Forbidden, $e->getMessage(), 403);
        }
        $visit = $this->service->recordWhatWasTaken($visit, $data, $request->user()->id);

        return ApiResponse::success($this->one($visit), 'New patient registered and visit opened — '.$visit->visit_no.'.', 201);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        return ApiResponse::success($this->one($visit));
    }

    public function vitals(VisitVitalsRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('recordVitals', $visit);

        $this->service->recordVitals($visit, $request->validated());

        return ApiResponse::success($this->one($visit), 'Vitals recorded.');
    }

    public function clinical(VisitClinicalRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('diagnose', $visit);

        $this->service->updateClinical($visit, $request->validated());

        return ApiResponse::success($this->one($visit), 'Clinical notes saved.');
    }

    public function transition(VisitTransitionRequest $request, Visit $visit): JsonResponse
    {
        $this->authorize('manage', $visit);

        try {
            $this->service->advance($visit, $request->user()->id, $request->validated('note'));
        } catch (Throwable $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($visit), 'Now at '.$visit->fresh()?->stage->label().'.');
    }

    /** Call the visit off. Always available while it is open, and always takes a reason. */
    public function cancel(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('manage', $visit);

        // The web's wording (Visits\Actions::cancelVisit), so the same mistake
        // reads the same everywhere.
        $note = $request->validate(VisitTransitionRequest::cancelRules(), [
            'note.required' => 'Say why the visit is being called off.',
            'note.min' => 'Say why the visit is being called off.',
        ])['note'];

        try {
            $this->service->cancel($visit, $request->user()->id, trim($note));
        } catch (InvalidVisitTransitionException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($visit), 'Visit cancelled.');
    }

    /**
     * The open-visit dialog's suggestions: this hospital's own reasons first,
     * then curated complaints and diagnoses (App\Support\VisitPhrases).
     */
    public function phrases(): JsonResponse
    {
        $this->authorize('create', Visit::class);

        return ApiResponse::success(['reason' => VisitPhrases::reasons()] + VisitPhrases::desk());
    }

    /**
     * What the notes form puts in front of whoever is writing — allergies,
     * conditions, the vitals, last time's diagnosis — and words to reach for.
     */
    public function writingAids(Visit $visit): JsonResponse
    {
        $this->authorize('diagnose', $visit);

        return ApiResponse::success([
            'context' => VisitPhrases::context($visit),
            'phrases' => array_map(fn (array $rows) => array_column($rows, 'value'), VisitPhrases::forNotes($visit)),
        ]);
    }

    /** One visit, as its page shows it: the gate and the trail. */
    private function one(Visit $visit): VisitResource
    {
        $visit = $visit->fresh(['patient', 'doctor', 'department', 'history.changedBy']) ?? $visit;

        return (new VisitResource($visit))->withGate($this->service->readiness($visit));
    }
}
