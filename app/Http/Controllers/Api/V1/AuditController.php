<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Patient;
use App\Models\RecordAccess;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\RecordAccessLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit log for the owner's phone (docs/api/audit.md): the same services
 * and the same permission (`manage-settings`) as the web screen. Read-only.
 */
class AuditController extends Controller
{
    /** GET /api/v1/audit-log — changes to clinical and money records, newest first. */
    public function changes(Request $request, AuditTrail $trail): JsonResponse
    {
        $this->authorize('manage-settings');

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditTrail::EVENTS))],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $trail->query([
            'type' => (string) ($data['type'] ?? ''),
            'event' => (string) ($data['event'] ?? ''),
            'user' => $data['user_id'] ?? null,
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
        ])->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (AuditEntry $e) => [
            'id' => $e->id,
            'at' => $e->created_at?->toIso8601String(),
            'type' => $e->log_name,
            'type_label' => AuditTrail::typeLabel($e->log_name),
            'record_id' => $e->subject_id,
            'event' => $e->event,
            'event_label' => AuditTrail::EVENTS[$e->event ?? ''] ?? $e->description,
            'who' => $e->user?->name,
            'via' => $e->properties?->get('via'),
            'fields' => AuditTrail::rows($e),
        ])->all());
    }

    /** GET /api/v1/patients/{uuid}/access-log — who opened or printed this record. */
    public function access(Request $request, Patient $patient, RecordAccessLog $log): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('manage-settings');

        $page = $log->query(['patient' => $patient->id])
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return ApiResponse::paginated($page, collect($page->items())
            ->map(fn (RecordAccess $a) => RecordAccessLog::toArray($a))->all());
    }
}
