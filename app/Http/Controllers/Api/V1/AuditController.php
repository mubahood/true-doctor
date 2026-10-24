<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Patient;
use App\Models\RecordAccess;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\RecordAccessLog;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The audit log for the owner's phone (docs/api/audit.md): the same services
 * and the same permission (`manage-settings`) as the web screen, with the
 * same two tabs (changes, records opened) and the same filters. Read-only.
 */
class AuditController extends Controller
{
    /** How the web names where a change came from. */
    private const VIA = ['api' => 'The app', 'web' => 'The web', 'system' => 'The system'];

    /** GET /api/v1/audit-log — changes to clinical and money records, newest first. */
    public function changes(Request $request, AuditTrail $trail): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditTrail::EVENTS))],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // The web's search box: a record's number (any other text matches the description).
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $trail->query([
            'type' => (string) ($data['type'] ?? ''),
            'event' => (string) ($data['event'] ?? ''),
            'user' => $data['user_id'] ?? null,
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
            'search' => (string) ($data['q'] ?? ''),
        ])->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (AuditEntry $e) => $this->change($e))->all());
    }

    /** GET /api/v1/audit-log/{id} — one change, field by field (the web's dialog). */
    public function show(int $entry): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();

        // Through the tenant scope: another hospital's entry is not found.
        $found = AuditEntry::query()->with('user:id,name,role')->findOrFail($entry);

        return ApiResponse::success($this->change($found));
    }

    /** GET /api/v1/audit-log/opens — who opened or printed which patient's record, newest first. */
    public function opens(Request $request, RecordAccessLog $log): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();

        $data = $request->validate([
            // A patient of this hospital only (the rule carries the tenant).
            'patient' => ['nullable', 'uuid', Rule::exists('patients', 'uuid')->where('hospital_id', app(CurrentHospital::class)->id())],
            'action' => ['nullable', 'string', Rule::in(array_keys(RecordAccess::ACTIONS))],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // A patient's name or number, the phone's search box (the web has a picker).
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['patient.exists' => 'That patient is not on this hospital\'s register.']);

        // An archived patient's opens stay in the log (the trail outlives the record), so it is found with them.
        $patientId = isset($data['patient']) ? Patient::withTrashed()->where('uuid', $data['patient'])->value('id') : null;
        if (isset($data['patient']) && $patientId === null) {
            // Never fall back to every patient's opens.
            return ApiResponse::error(\App\Enums\ApiErrorCode::ValidationFailed, 'That patient is not on this hospital\'s register.', 422, ['patient' => ['That patient is not on this hospital\'s register.']]);
        }

        $page = $log->query([
            'patient' => $patientId,
            'user' => $data['user_id'] ?? null,
            'action' => (string) ($data['action'] ?? ''),
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
            'search' => (string) ($data['q'] ?? ''),
        ])->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (RecordAccess $a) => ['id' => $a->id] + RecordAccessLog::toArray($a) + [
            'patient' => $a->patient === null ? null : [
                'uuid' => $a->patient->uuid,
                'name' => $a->patient->full_name,
                'patient_no' => $a->patient->patient_no,
            ],
        ])->all());
    }

    /** GET /api/v1/audit-log/filters — the choices the web's filter bar offers. */
    public function filters(): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();

        $options = fn (array $map) => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($map), $map);

        return ApiResponse::success([
            'types' => $options(AuditTrail::types()),
            'events' => $options(AuditTrail::EVENTS),
            'actions' => $options(RecordAccess::ACTIONS),
            // Everybody with an account here, people who have left included:
            // their changes stay in the trail (User carries no tenant scope).
            'people' => User::query()->currentHospital()->orderBy('name')->get(['id', 'name', 'role', 'is_active'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role_label' => $u->role_label, 'is_active' => (bool) $u->is_active])
                ->values()->all(),
        ]);
    }

    /** GET /api/v1/patients/{uuid}/access-log — who opened or printed this record. */
    public function access(Request $request, Patient $patient, RecordAccessLog $log): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('manage-settings');
        $this->oneHospital();

        $page = $log->query(['patient' => $patient->id])
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return ApiResponse::paginated($page, collect($page->items())
            ->map(fn (RecordAccess $a) => RecordAccessLog::toArray($a))->all());
    }

    /** @return array<string,mixed> one change, as a row and in the dialog */
    private function change(AuditEntry $e): array
    {
        $via = $e->properties?->get('via');

        return [
            'id' => $e->id,
            'at' => $e->created_at?->toIso8601String(),
            'type' => $e->log_name,
            'type_label' => AuditTrail::typeLabel($e->log_name),
            'record_id' => $e->subject_id,
            'event' => $e->event,
            'event_label' => AuditTrail::EVENTS[$e->event ?? ''] ?? ucfirst((string) $e->description),
            'description' => $e->description,
            'who' => $e->user?->name,
            'via' => $via,
            'via_label' => self::VIA[$via] ?? null,
            'ip' => $e->properties?->get('ip'),
            'summary' => AuditTrail::summary($e),
            'fields' => AuditTrail::rows($e),
        ];
    }

    /**
     * One clinic's log only: with no hospital resolved (a platform account)
     * the tenant scope filters nothing and every clinic's rows would answer.
     */
    private function oneHospital(): void
    {
        abort_if(app(\App\Support\CurrentHospital::class)->id() === null, 403, 'This is kept by each clinic. Open it from inside the clinic.');
    }
}
