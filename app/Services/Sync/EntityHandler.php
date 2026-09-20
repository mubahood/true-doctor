<?php

namespace App\Services\Sync;

use App\Models\SyncOperation;
use App\Models\User;

/**
 * How one kind of record is applied when it arrives from a device.
 *
 * One handler per entity, and every one of them goes through the domain
 * Service that already owns the rule — `PatientService::register`,
 * `VisitService::recordVitals`, `StockService::post`. That is the single most
 * important implementation rule in the offline system:
 *
 *   **Sync never writes rows directly.**
 *
 * The moment it does, every invariant those Services hold — bed locking,
 * sequence allocation, ledger balances, closed-period guards, plan limits — is
 * bypassed for offline users only, which is precisely the population least able
 * to notice that it happened.
 *
 * A handler returns a result the client can act on. The four shapes are fixed
 * by the protocol (plan §9.1): accepted, rejected, conflict, and — produced by
 * the ledger rather than here — already_processed.
 */
abstract class EntityHandler
{
    /** The `entity` string the client sends. */
    abstract public function entity(): string;

    /**
     * Apply the operation. Runs inside the per-operation transaction opened by
     * `OperationLedger::once()`, so throwing rolls the whole thing back.
     *
     * `$meta` carries what the envelope knows and the payload does not —
     * today just `base_fields`, the values the device last had confirmed for
     * the fields it is changing, which is the third side of a three-way merge.
     * Passed separately rather than folded into the payload so a handler
     * cannot mistake it for something the record actually holds.
     *
     * @param  array<string,mixed>  $payload
     * @param  array{base_fields?: array<string,mixed>}  $meta
     * @return array<string,mixed>
     */
    abstract public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array;

    /**
     * May this user do this at all?
     *
     * Checked server-side for every operation, never once per batch: a device
     * whose user lost a permission while it was offline must be refused now,
     * not trusted because the batch header looked fine (invariant I-15).
     */
    abstract public function authorize(string $operation, array $payload, User $actor): bool;

    // ── The four answers ─────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $assigned  server-allocated values the device
     *                                         could not know — `patient_no`,
     *                                         `visit_no`, a computed balance
     * @return array<string,mixed>
     */
    protected function accepted(?int $serverId, int $version = 1, array $assigned = []): array
    {
        return array_filter([
            'status' => 'accepted',
            'server_id' => $serverId,
            'version' => $version,
            'assigned' => $assigned ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The server said no, and says why in words a person can act on.
     *
     * @param  array<string,array<int,string>>|null  $errors  per-field, so the
     *                                                        device can put the
     *                                                        message beside the
     *                                                        field that caused it
     * @return array<string,mixed>
     */
    protected function rejected(string $reasonCode, string $message, ?array $errors = null): array
    {
        return array_filter([
            'status' => 'rejected',
            'reason_code' => $reasonCode,
            'message' => $message,
            'errors' => $errors,
        ], fn ($v) => $v !== null);
    }

    /**
     * Somebody else changed this record first.
     *
     * `contested` names the fields in dispute — names only, never values: a
     * clinical value echoed into a conflict record is a second copy of the
     * record under nobody's governance (plan §20).
     *
     * @param  array<string,mixed>  $serverState  what the server holds now, so
     *                                            the device can show both sides
     * @return array<string,mixed>
     */
    protected function conflict(
        string $strategy,
        ?int $baseVersion,
        int $serverVersion,
        array $contested = [],
        array $merged = [],
        array $serverState = [],
    ): array {
        return [
            'status' => 'conflict',
            'conflict' => [
                'strategy' => $strategy,
                'base_version' => $baseVersion,
                'server_version' => $serverVersion,
                'contested' => array_values($contested),
                'merged' => array_values($merged),
                'server_state' => $serverState,
            ],
        ];
    }
}
