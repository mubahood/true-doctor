<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SharedDocumentType;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PatientLinks\SharedDocuments;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Patient links over the API (docs/api/patient-links.md): the same service as
 * the web's "Send to patient" button and the Patient links page.
 */
class PatientLinkController extends Controller
{
    public function __construct(
        private readonly PatientLinkService $links,
        private readonly SharedDocuments $documents,
    ) {}

    /** GET patient-links?patient={uuid}&state=active|expired|revoked|locked */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PatientLink::class);
        $data = $request->validate([
            'patient' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(PatientLink::STATES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $patientId = isset($data['patient']) ? Patient::query()->where('uuid', $data['patient'])->value('id') ?? abort(404) : null;

        $page = PatientLink::query()->with(['patient:id,uuid,first_name,last_name,patient_no', 'creator:id,name'])
            ->when($patientId !== null, fn ($q) => $q->where('patient_id', $patientId))
            ->when(isset($data['state']), fn ($q) => $q->inState($data['state']))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (PatientLink $l) => $this->row($l))->all());
    }

    /**
     * POST patient-links {document_type, document_uuid, days?, send_sms?}
     *
     * The answer carries the link and its code once; they cannot be read back.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::enum(SharedDocumentType::class)],
            'document_uuid' => ['required', 'uuid'],
            'days' => ['nullable', 'integer', Rule::in(PatientLinkService::DAY_CHOICES)],
            'send_sms' => ['nullable', 'boolean'],
        ]);

        $type = SharedDocumentType::from($data['document_type']);
        abort_unless($type->allows($request->user()), 403);
        $record = $this->documents->find($type, $data['document_uuid']) ?? abort(404);

        $made = $this->links->issue($type, $record, $request->user(), (int) ($data['days'] ?? PatientLinkService::DAYS), (bool) ($data['send_sms'] ?? false));
        $message = $made['message'];

        return ApiResponse::success([
            'link' => $this->row($made['link']->load(['patient', 'creator'])),
            'url' => $made['url'],
            'code' => $made['code'],
            'message' => $message === null ? null : [
                'uuid' => $message->uuid,
                'status' => $message->status->value,
                'status_label' => $message->status->label(),
                'skip_reason' => $message->skip_reason,
            ],
        ], 'Link ready.', 201);
    }

    /** GET patient-links/{uuid}: the link and its last 50 opens. */
    public function show(PatientLink $patientLink): JsonResponse
    {
        $this->authorize('view', $patientLink);
        $patientLink->load(['patient', 'creator']);

        return ApiResponse::success($this->row($patientLink) + [
            'opens' => $patientLink->opens()->limit(50)->get()->map(fn (PatientLinkOpen $o) => [
                'event' => $o->event,
                'event_label' => PatientLinkOpen::eventLabel($o->event),
                'ip' => $o->ip,
                'user_agent' => $o->user_agent,
                'at' => $o->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** DELETE patient-links/{uuid}: withdraw it. Safe to repeat. */
    public function destroy(Request $request, PatientLink $patientLink): JsonResponse
    {
        $this->authorize('revoke', $patientLink);
        $this->links->revoke($patientLink, $request->user());

        return ApiResponse::success($this->row($patientLink->load(['patient', 'creator'])), 'The link no longer opens.');
    }

    /** @return array<string,mixed> */
    private function row(PatientLink $l): array
    {
        $state = $l->state();

        return [
            'uuid' => $l->uuid,
            'document_type' => $l->document_type->value,
            'document_label' => $l->document_type->label(),
            'reference' => $l->reference,
            'patient' => $l->patient === null ? null : [
                'uuid' => $l->patient->uuid,
                'name' => $l->patient->full_name,
                'patient_no' => $l->patient->patient_no,
            ],
            'state' => $state,
            'state_label' => PatientLink::stateLabel($state),
            'expires_at' => $l->expires_at->toIso8601String(),
            'revoked_at' => $l->revoked_at?->toIso8601String(),
            'locked_at' => $l->locked_at?->toIso8601String(),
            'failed_attempts' => $l->failed_attempts,
            'opens_count' => $l->opens_count,
            'last_opened_at' => $l->last_opened_at?->toIso8601String(),
            'created_by' => $l->creator?->name,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
