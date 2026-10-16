<?php

namespace App\Services\Sync\Handlers;

use App\Models\DentalChartEntry;
use App\Models\Patient;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalService;
use App\Services\Sync\EntityHandler;
use App\Support\SyncRevision;
use Illuminate\Validation\ValidationException;

/**
 * Dental findings recorded on a tablet with no network (`dental_chart_entries`).
 *
 * The chart is append-only, which makes it as safe to sync as vitals: two
 * devices charting two findings charted two findings. The device's uuid is
 * the entry's uuid, so a replay finds the entry instead of making a second.
 * Treatment items are NOT synced this way: finishing one bills the patient,
 * which is the server's to judge with a connection.
 */
class DentalChartHandler extends EntityHandler
{
    public function __construct(private readonly DentalService $dental) {}

    public function entity(): string
    {
        return 'dental_chart_entries';
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        return $actor->can('visits.diagnose');
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        if ($operation !== 'create') {
            return $this->rejected('append_only', 'A finding cannot be changed. Record a new one that corrects it instead.');
        }

        $existing = DentalChartEntry::query()->where('uuid', (string) ($payload['uuid'] ?? ''))->first();
        if ($existing !== null) {
            return $this->accepted((int) $existing->getKey(), 1);
        }

        $patient = Patient::query()->where('uuid', (string) ($payload['patient_uuid'] ?? ''))->first();
        if ($patient === null) {
            return $this->rejected('parent_missing', 'The patient has not reached the server yet. It will be sent again.');
        }
        $visit = isset($payload['visit_uuid']) ? Visit::query()->where('uuid', (string) $payload['visit_uuid'])->where('patient_id', $patient->id)->first() : null;

        try {
            [$entry] = $this->dental->record($patient, [[
                'uuid' => $payload['uuid'] ?? null,
                'tooth' => $payload['tooth'] ?? null,
                'finding' => $payload['finding'] ?? null,
                'surfaces' => $payload['surfaces'] ?? null,
                'mobility' => $payload['mobility'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]], $visit, $actor);
        } catch (ValidationException $e) {
            return $this->rejected('validation_failed', 'This finding could not be saved as recorded.', $e->errors());
        }

        $entry->forceFill([
            'sync_revision' => SyncRevision::next(),
            'origin_device_id' => $record->device_id,
            'origin_operation_id' => $record->operation_id,
            'client_created_at' => $record->client_created_at,
        ])->saveQuietly();

        return $this->accepted((int) $entry->getKey(), 1);
    }
}
