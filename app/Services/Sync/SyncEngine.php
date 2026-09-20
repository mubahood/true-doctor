<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\Sync\Handlers\AppendOnlyClinicalHandler;
use App\Services\Sync\Handlers\LabResultHandler;
use App\Services\Sync\Handlers\PatientHandler;
use App\Services\Sync\Handlers\VisitClinicalHandler;
use App\Support\CurrentHospital;
use Illuminate\Support\Str;

/**
 * Applies a batch of operations from a device.
 *
 * Three rules the whole endpoint is built around:
 *
 * **One transaction per operation, not per batch.** A batch of forty is forty
 * independent pieces of work; one bad vitals reading must not roll back
 * thirty-nine good ones. The transaction lives inside `OperationLedger::once()`
 * so it always wraps exactly the right scope (plan §9.2, §9.5).
 *
 * **Authorisation per operation, never per batch.** A user whose permission was
 * taken away while their device was offline must be refused now, on every
 * operation, not trusted because the batch header carried a valid token
 * (invariant I-15).
 *
 * **Tenancy from the token, never from the payload.** The hospital is whatever
 * the authenticated user belongs to. A device that sent its own `hospital_id`
 * would be telling the server which tenant to write into.
 */
class SyncEngine
{
    /** More than this in one request is refused rather than silently truncated. */
    public const MAX_BATCH = 200;

    /** The configured cap, where a hospital has set one. */
    public static function maxBatch(): int
    {
        return (int) config('offline.push.max_batch', self::MAX_BATCH);
    }

    /** @var array<string, EntityHandler>|null */
    private ?array $handlers = null;

    public function __construct(
        private readonly OperationLedger $ledger,
        private readonly CurrentHospital $current,
    ) {}

    /**
     * @param  array<int, array<string,mixed>>  $operations
     * @return array{session_uuid: string, results: array<int, array<string,mixed>>, server_time: string}
     */
    public function push(array $operations, User $actor, ?Device $device, ?string $clientProtocol = null): array
    {
        $startedAt = microtime(true);

        $session = SyncSession::create([
            'session_uuid' => (string) Str::uuid(),
            'hospital_id' => $this->current->id(),
            'user_id' => $actor->id,
            'device_id' => $device?->id,
            'kind' => 'push',
            'operation_count' => count($operations),
            'client_protocol' => $clientProtocol,
        ]);

        $results = [];
        $tally = ['accepted' => 0, 'rejected' => 0, 'conflict' => 0, 'duplicate' => 0];

        foreach ($operations as $operation) {
            $operation['user_id'] = $actor->id;
            $operation['device_id'] = $device?->id;
            $operation['sync_session_id'] = $session->id;

            $outcome = $this->ledger->once($operation, function (SyncOperation $record) use ($operation, $actor) {
                return $this->applyOne($operation, $record, $actor);
            });

            $result = $outcome['result'];

            // A replayed operation is reported as `already_processed` so the
            // device can tell "the server did this" from "the server has just
            // done this" — the two mean different things in a sync log even
            // though the local handling is identical.
            if ($outcome['replayed'] && ($result['status'] ?? null) === 'accepted') {
                $result['status'] = 'already_processed';
                $tally['duplicate']++;
            } else {
                $tally[$result['status'] ?? 'rejected'] = ($tally[$result['status'] ?? 'rejected'] ?? 0) + 1;
            }

            if (($result['status'] ?? null) === 'conflict') {
                $this->recordConflict($operation, $result, $actor, $device);
            }

            $results[] = $result;
        }

        $session->update([
            'accepted_count' => $tally['accepted'] ?? 0,
            'rejected_count' => $tally['rejected'] ?? 0,
            'conflict_count' => $tally['conflict'] ?? 0,
            'duplicate_count' => $tally['duplicate'] ?? 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $device?->update(['last_sync_at' => now(), 'last_seen_at' => now()]);

        return [
            'session_uuid' => $session->session_uuid,
            'results' => $results,
            // So the device can measure its clock against ours (plan §7.3).
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function applyOne(array $operation, SyncOperation $record, User $actor): array
    {
        $entity = (string) ($operation['entity'] ?? '');
        $handler = $this->handlerFor($entity);

        if ($handler === null) {
            return [
                'status' => 'rejected',
                'reason_code' => 'unknown_entity',
                'message' => "This version of the server does not accept '{$entity}' from a device.",
            ];
        }

        $action = (string) ($operation['operation'] ?? '');
        $payload = (array) ($operation['payload'] ?? []);

        if (! $handler->authorize($action, $payload, $actor)) {
            // Permissions may have changed while the device was away. The
            // message says so rather than reading as a bug, because from the
            // clinician's side nothing about their work changed.
            return [
                'status' => 'rejected',
                'reason_code' => 'forbidden',
                'message' => 'Your permissions no longer allow this. It was recorded on your device but cannot be saved.',
            ];
        }

        return $handler->apply($action, $payload, $record, $actor, [
            'base_fields' => (array) ($operation['base_fields'] ?? []),
        ]);
    }

    /**
     * @param  array<string,mixed>  $operation
     * @param  array<string,mixed>  $result
     */
    private function recordConflict(array $operation, array $result, User $actor, ?Device $device): void
    {
        $conflict = $result['conflict'] ?? [];

        SyncConflict::create([
            'hospital_id' => $this->current->id(),
            'device_id' => $device?->id,
            'user_id' => $actor->id,
            'operation_id' => (string) $operation['operation_id'],
            'entity' => (string) ($operation['entity'] ?? ''),
            'entity_uuid' => (string) ($operation['entity_uuid'] ?? ''),
            'strategy' => (string) ($conflict['strategy'] ?? 'manual'),
            'base_version' => $conflict['base_version'] ?? null,
            'server_version' => $conflict['server_version'] ?? null,
            // Field NAMES only. A clinical value echoed into a conflict record
            // is a second copy of the record under nobody's governance.
            'contested_fields' => $conflict['contested'] ?? [],
            'merged_fields' => $conflict['merged'] ?? [],
        ]);
    }

    private function handlerFor(string $entity): ?EntityHandler
    {
        $this->handlers ??= [
            'patients' => app(PatientHandler::class),
            'vitals' => new AppendOnlyClinicalHandler('vitals'),
            'nursing_notes' => new AppendOnlyClinicalHandler('nursing_notes'),
            'med_administrations' => new AppendOnlyClinicalHandler('med_administrations'),
            'lab_items' => app(LabResultHandler::class),
            'visits' => app(VisitClinicalHandler::class),
        ];

        return $this->handlers[$entity] ?? null;
    }

    /** Which entities this server will accept, for the client to check on
     *  connect rather than discovering one operation at a time. */
    public function acceptedEntities(): array
    {
        $this->handlerFor('');

        return array_keys($this->handlers ?? []);
    }
}
