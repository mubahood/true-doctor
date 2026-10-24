<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\DataRequestKind;
use App\Enums\DataRequestStatus;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Models\DataRequest;
use App\Models\Patient;
use App\Services\Compliance\DataRequestService;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Data requests for the app (docs/api/admin.md): every time a patient asked
 * to see, correct or stop a use of their data, when the answer is due, and
 * what was done. DataRequestService holds the rules (logged once, closed
 * once, never edited), as it does for the web screen; `manage-settings`, and
 * one clinic's log only.
 */
class DataRequestController extends Controller
{
    use ReadsAdminInput;

    public function __construct(private readonly DataRequestService $requests) {}

    /** GET data-requests?status=&kind=&q= */
    public function index(Request $request): JsonResponse
    {
        $this->allowed();
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(DataRequestStatus::class)],
            'kind' => ['nullable', Rule::enum(DataRequestKind::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->requests->query([
            'status' => (string) ($data['status'] ?? ''),
            'kind' => (string) ($data['kind'] ?? ''),
            'search' => (string) ($data['q'] ?? ''),
        ])->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (DataRequest $r) => $this->row($r))->all(), [
            'response_days' => (int) config('compliance.response_days', 30),
        ]);
    }

    /** GET data-requests/{uuid} */
    public function show(DataRequest $dataRequest): JsonResponse
    {
        $this->allowed();

        return ApiResponse::success($this->detail($dataRequest));
    }

    /** POST data-requests {patient_uuid?, requester_name, requester_contact?, kind, received_on, details?} */
    public function store(Request $request): JsonResponse
    {
        $this->allowed();

        $input = $request->only(['requester_name', 'requester_contact', 'kind', 'received_on', 'details']);
        // The app names the patient by uuid; the shared rules check the id against this hospital.
        if ($request->filled('patient_uuid')) {
            $patient = Patient::query()->where('uuid', (string) $request->input('patient_uuid'))->first();
            if ($patient === null) {
                $message = 'That patient is not on this hospital\'s register.';

                return ApiResponse::error(ApiErrorCode::ValidationFailed, $message, 422, ['patient_uuid' => [$message]]);
            }
            $input['patient_id'] = $patient->id;
        }

        $data = $this->validated($input, DataRequestService::rules());
        $logged = $this->requests->log($data, $request->user());

        return ApiResponse::success(
            $this->detail($logged),
            'Request logged. The answer is due within '.config('compliance.response_days').' days.',
            201,
        );
    }

    /** POST data-requests/{uuid}/answer {outcome} */
    public function answer(Request $request, DataRequest $dataRequest): JsonResponse
    {
        return $this->close($request, $dataRequest, DataRequestStatus::Done);
    }

    /** POST data-requests/{uuid}/refuse {outcome}: the reason the patient is given. */
    public function refuse(Request $request, DataRequest $dataRequest): JsonResponse
    {
        return $this->close($request, $dataRequest, DataRequestStatus::Refused);
    }

    private function close(Request $request, DataRequest $dataRequest, DataRequestStatus $status): JsonResponse
    {
        $this->allowed();
        $request->validate(['outcome' => ['required', 'string', 'max:2000']], [], ['outcome' => 'what was done']);

        $closed = $this->requests->close($dataRequest, $status, (string) $request->input('outcome'), $request->user());

        return ApiResponse::success(
            $this->detail($closed),
            $status === DataRequestStatus::Done ? 'Marked as answered.' : 'Marked as refused, with the reason.',
        );
    }

    /** The web's own check: the settings permission, inside one hospital. */
    private function allowed(): void
    {
        $this->authorize('manage-settings');
        // With no hospital resolved the tenant scope filters nothing, and a
        // platform account would read every clinic's requests.
        abort_if(app(CurrentHospital::class)->id() === null, 403, 'Data requests are kept by each clinic. Open them from inside the clinic.');
    }

    /** @return array<string,mixed> */
    private function row(DataRequest $r): array
    {
        return [
            'uuid' => $r->uuid,
            'requester_name' => $r->requester_name,
            'requester_contact' => $r->requester_contact,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'status_tone' => $r->status->tone(),
            'overdue' => $r->isOverdue(),
            'received_on' => $r->received_on->toDateString(),
            'due_on' => $r->due_on->toDateString(),
            'patient' => $r->patient === null ? null : [
                'uuid' => $r->patient->uuid,
                'name' => $r->patient->full_name,
                'patient_no' => $r->patient->patient_no,
            ],
        ];
    }

    /** @return array<string,mixed> the row, with what the web's dialog shows */
    private function detail(DataRequest $r): array
    {
        $r->loadMissing(['patient:id,uuid,first_name,last_name,patient_no', 'recorder:id,name', 'closer:id,name']);

        return $this->row($r) + [
            'details' => $r->details,
            'hint' => $r->kind->hint(),
            'recorded_by' => $r->recorder?->name,
            'created_at' => $r->created_at?->toIso8601String(),
            'outcome' => $r->outcome,
            'closed_at' => $r->closed_at?->toIso8601String(),
            'closed_by' => $r->closer?->name,
            // "Their data (PDF)" for a request to see it: GET patients/{uuid}/personal-data.pdf
            'personal_data' => $r->kind === DataRequestKind::Access && $r->patient !== null,
        ];
    }
}
