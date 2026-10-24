<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SharedDocumentType;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Models\User;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PatientLinks\SharedDocuments;
use App\Services\QrService;
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

    /** GET patient-links?patient={uuid}&state=active|expired|revoked|locked&q= */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PatientLink::class);
        $data = $request->validate([
            'patient' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(PatientLink::STATES)],
            // The web list's search box: the patient's name or number, the document's number.
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $patientId = isset($data['patient']) ? Patient::query()->where('uuid', $data['patient'])->value('id') ?? abort(404) : null;

        $page = PatientLink::query()->with(['patient:id,uuid,first_name,last_name,patient_no', 'creator:id,name'])
            ->when($patientId !== null, fn ($q) => $q->where('patient_id', $patientId))
            ->when(isset($data['state']), fn ($q) => $q->inState($data['state']))
            ->matching((string) ($data['q'] ?? ''))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (PatientLink $l) => $this->row($l, $request->user()))->all());
    }

    /**
     * GET patients/{patient}/shareable: what this person may send to this
     * patient by link now (SharedDocuments::readyFor), the expiry choices,
     * and whether the link can be texted, with the reason when not.
     */
    public function shareable(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('viewAny', PatientLink::class);

        return ApiResponse::success([
            'documents' => array_map(fn (array $d) => [
                'type' => $d['type']->value,
                'type_label' => $d['type']->label(),
                'uuid' => (string) $d['record']->getAttribute('uuid'),
                'reference' => $d['reference'],
                'title' => $d['title'],
                'date' => $d['date'],
            ], $this->documents->readyFor($patient, $request->user())),
            'days' => PatientLinkService::DAY_CHOICES,
            'default_days' => PatientLinkService::DAYS,
            'can_text' => $this->links->canText($patient),
            'text_note' => $this->links->textNote($patient),
            'max_attempts' => PatientLink::MAX_ATTEMPTS,
        ]);
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
            'link' => $this->row($made['link']->load(['patient', 'creator']), $request->user()),
            'url' => $made['url'],
            'code' => $made['code'],
            // The link as a QR for the patient's phone camera (a PNG data URI), or null if it could not be drawn.
            'qr' => $this->qr($made['url']),
            'message' => $message === null ? null : [
                'uuid' => $message->uuid,
                'status' => $message->status->value,
                'status_label' => $message->status->label(),
                'skip_reason' => $message->skip_reason,
            ],
        ], 'Link ready.', 201);
    }

    /** GET patient-links/{uuid}: the link, its text message, and its last 50 opens. */
    public function show(Request $request, PatientLink $patientLink): JsonResponse
    {
        $this->authorize('view', $patientLink);
        $patientLink->load(['patient', 'creator', 'revoker:id,name', 'message']);
        $message = $patientLink->message;

        return ApiResponse::success($this->row($patientLink, $request->user()) + [
            'revoked_by' => $patientLink->revoker?->name,
            'message' => $message === null ? null : [
                'status' => $message->status->value,
                'status_label' => $message->status->label(),
                'skip_reason' => $message->skip_reason,
            ],
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

        return ApiResponse::success($this->row($patientLink->load(['patient', 'creator']), $request->user()), 'The link no longer opens.');
    }

    /** @return array<string,mixed> */
    private function row(PatientLink $l, ?User $viewer): array
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
            'state_tone' => PatientLink::stateTone($state),
            'expires_at' => $l->expires_at->toIso8601String(),
            'revoked_at' => $l->revoked_at?->toIso8601String(),
            'locked_at' => $l->locked_at?->toIso8601String(),
            'failed_attempts' => $l->failed_attempts,
            'opens_count' => $l->opens_count,
            'last_opened_at' => $l->last_opened_at?->toIso8601String(),
            'created_by' => $l->creator?->name,
            'created_at' => $l->created_at?->toIso8601String(),
            // Withdrawing takes the right to print the document (PatientLinkPolicy@revoke), and only a link that still opens or is locked.
            'can_revoke' => in_array($state, [PatientLink::ACTIVE, PatientLink::LOCKED], true) && ($viewer?->can('revoke', $l) ?? false),
        ];
    }

    /** The link as a QR (PNG data URI). Drawing it never stops the link being made. */
    private function qr(string $url): ?string
    {
        try {
            return app(QrService::class)->pngDataUri($url, 240);
        } catch (\Throwable) {
            return null;
        }
    }
}
