<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncOperation;
use App\Models\User;
use App\Models\Visit;
use App\Services\DiagnosisService;
use App\Services\Sync\EntityHandler;
use Illuminate\Validation\ValidationException;

/**
 * A diagnosis coded on a device with no signal (`visit_diagnoses`, create
 * only). The device's uuid is the row's, so a replay finds it instead of
 * coding it twice; a code already on the visit is accepted as done.
 */
class VisitDiagnosisHandler extends EntityHandler
{
    public function __construct(private readonly DiagnosisService $diagnoses) {}

    public function entity(): string
    {
        return 'visit_diagnoses';
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        return $actor->can('visits.diagnose');
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        if ($operation !== 'create') {
            return $this->rejected('append_only', 'A coded diagnosis is changed on the visit, with a connection.');
        }

        $visit = Visit::query()->where('uuid', (string) ($payload['visit_uuid'] ?? ''))->first();
        if ($visit === null) {
            return $this->rejected('parent_missing', 'The visit has not reached the server yet. It will be sent again.');
        }

        $code = strtoupper((string) ($payload['code'] ?? ''));
        $already = $this->diagnoses->current($visit)->firstWhere('code', $code);
        if ($already !== null) {
            return $this->accepted($already->id, 1);
        }

        try {
            $d = $this->diagnoses->add($visit, $code, (bool) ($payload['primary'] ?? false), (string) ($payload['certainty'] ?? 'confirmed'), $payload['note'] ?? null, $actor, isset($payload['uuid']) ? (string) $payload['uuid'] : null);
        } catch (ValidationException $e) {
            return $this->rejected('validation_failed', 'This diagnosis could not be recorded as sent.', $e->errors());
        }

        return $this->accepted($d->id, 1);
    }
}
