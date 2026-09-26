<?php

namespace App\Services\Sync;

use App\Models\SyncOperation;
use App\Support\CurrentHospital;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Apply an operation at most once, whatever the network does.
 *
 * ── The problem ──────────────────────────────────────────────────────────
 * A device pushes CREATE_PATIENT. The server commits. The response is lost —
 * a dropped connection, a closed laptop, a proxy timing out. From the device's
 * side that is indistinguishable from "the request never arrived", so it
 * retries. Without a gate the retry creates a second patient, and nobody finds
 * out until two records with the same name are discovered weeks later.
 *
 * ── The gate ─────────────────────────────────────────────────────────────
 * The guarantee is the UNIQUE INDEX on `sync_operations.operation_id`. Not a
 * check-then-insert in PHP: two replays arriving together would both pass the
 * check and both insert. The insert IS the lock — one wins, the loser catches
 * the constraint violation and reads back what the winner produced.
 *
 * The claim row is committed in its OWN transaction, before the work starts.
 * That ordering is deliberate and worth spelling out: if the claim were inside
 * the work's transaction, a rollback would erase the claim too, and a replay
 * arriving mid-retry would be free to apply the operation a second time. The
 * cost is that a process killed between claim and result leaves a row stuck in
 * `processing` — recovered by `reclaimStale()` rather than by weakening the
 * gate (invariants I-1, I-2).
 *
 * @see docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md §9.3
 */
class OperationLedger
{
    /** A claim older than this was abandoned by a dead process. */
    public const STALE_CLAIM_MINUTES = 10;

    /**
     * Settled answers that are NOT final: nothing was applied, and the reason
     * can go away on its own. A retry of the same operation id runs the work
     * again instead of being handed the old answer for ever.
     *
     *  - `server_error` — the work threw; its transaction rolled back.
     *  - `abandoned`    — the process died; reclaimStale() marked it.
     *  - `parent_missing` — the record it hangs off had not arrived yet.
     *
     * Everything else — accepted, conflict, a validation or permission
     * refusal — is an answer about the data, and a replay gets it verbatim
     * (invariant I-2).
     */
    public const RETRYABLE = ['server_error', 'abandoned', 'parent_missing'];

    /** How long a result is replayable. Past it, a replay is refused rather
     *  than applied blind — the client's backoff caps well inside this. */
    public const RETENTION_DAYS = 90;

    public function __construct(private readonly CurrentHospital $current) {}

    /**
     * Run `$work` for this operation, exactly once.
     *
     * @param  callable(SyncOperation): array{status:string, server_id?:int|null, version?:int|null, assigned?:array<string,mixed>, reason_code?:string|null, message?:string|null, errors?:array<string,mixed>|null, conflict?:array<string,mixed>|null}  $work
     * @return array{result: array<string,mixed>, replayed: bool}
     */
    public function once(array $operation, callable $work): array
    {
        $operationId = (string) ($operation['operation_id'] ?? '');

        if ($operationId === '') {
            return [
                'result' => $this->refusal('missing_operation_id', 'Every operation must carry an operation_id.'),
                'replayed' => false,
            ];
        }

        $claim = $this->claim($operationId, $operation);

        if ($claim['replayed']) {
            return ['result' => $claim['result'], 'replayed' => true];
        }

        /** @var SyncOperation $row */
        $row = $claim['row'];

        try {
            // One transaction per operation (plan §9.2). Without it a handler
            // that writes two rows and fails on the second leaves the first
            // behind — and because the claim is settled as `failed`, the retry
            // writes it AGAIN. That is a duplicate the idempotency gate cannot
            // catch, because the operation genuinely never completed.
            //
            // It is deliberately NOT the same transaction as the claim: see
            // the class docblock. The claim must outlive a rolled-back attempt.
            $outcome = DB::transaction(fn () => $work($row));
        } catch (Throwable $e) {
            // The work's own transaction has already rolled back. The claim
            // survives on purpose: a retry must find the operation and be told
            // it may try again, rather than silently starting from scratch
            // while the first attempt is still unwinding somewhere.
            report($e);

            $this->settle($row, [
                'status' => 'failed',
                'reason_code' => 'server_error',
                'message' => 'The server could not apply this operation. It will be retried.',
            ]);

            return [
                'result' => $this->decorate($operationId, [
                    'status' => 'failed',
                    'reason_code' => 'server_error',
                    'message' => 'The server could not apply this operation. It will be retried.',
                ]),
                'replayed' => false,
            ];
        }

        $this->settle($row, $outcome);

        return ['result' => $this->decorate($operationId, $outcome), 'replayed' => false];
    }

    /**
     * Stake a claim on this operation id.
     *
     * @return array{replayed: bool, row?: SyncOperation, result?: array<string,mixed>}
     */
    private function claim(string $operationId, array $operation): array
    {
        $payloadHash = hash('sha256', json_encode($operation['payload'] ?? [], JSON_THROW_ON_ERROR));

        try {
            // Its own transaction, committed before the work begins. See the
            // class docblock for why the ordering is not negotiable.
            $row = DB::transaction(fn () => SyncOperation::create([
                'operation_id' => $operationId,
                'hospital_id' => $this->current->id(),
                'user_id' => $operation['user_id'],
                'device_id' => $operation['device_id'] ?? null,
                'sync_session_id' => $operation['sync_session_id'] ?? null,
                'entity' => (string) ($operation['entity'] ?? 'unknown'),
                'entity_uuid' => (string) ($operation['entity_uuid'] ?? ''),
                'operation' => (string) ($operation['operation'] ?? 'unknown'),
                'status' => 'processing',
                'base_version' => $operation['base_version'] ?? null,
                'payload_hash' => $payloadHash,
                'client_created_at' => $operation['client_created_at'] ?? null,
            ]));

            return ['replayed' => false, 'row' => $row];
        } catch (UniqueConstraintViolationException) {
            // Somebody got here first — an earlier attempt, or a concurrent
            // replay racing us right now.
            return $this->replayOf($operationId, $payloadHash);
        }
    }

    /**
     * @return array{replayed: bool, row?: SyncOperation, result?: array<string,mixed>}
     */
    private function replayOf(string $operationId, string $payloadHash): array
    {
        /** @var SyncOperation|null $existing */
        $existing = SyncOperation::withoutGlobalScopes()->where('operation_id', $operationId)->first();

        if ($existing === null) {
            // The unique index fired but the row is not visible — the winner's
            // transaction has not committed yet. Telling the client to retry is
            // the honest answer; inventing a result would be a guess.
            return [
                'replayed' => true,
                'result' => $this->decorate($operationId, [
                    'status' => 'failed',
                    'reason_code' => 'in_flight',
                    'message' => 'This operation is already being processed. It will be retried.',
                ]),
            ];
        }

        // Cross-tenant replay: a device must not learn that another hospital's
        // operation id exists, and must certainly not receive its result.
        if ($this->current->id() !== null && (int) $existing->hospital_id !== (int) $this->current->id()) {
            return [
                'replayed' => true,
                'result' => $this->decorate($operationId, [
                    'status' => 'rejected',
                    'reason_code' => 'unknown_operation',
                    'message' => 'This operation does not belong to this hospital.',
                ]),
            ];
        }

        if ($existing->status === 'processing') {
            $abandoned = $existing->created_at !== null
                && $existing->created_at->lt(now()->subMinutes(self::STALE_CLAIM_MINUTES));

            if (! $abandoned) {
                return [
                    'replayed' => true,
                    'result' => $this->decorate($operationId, [
                        'status' => 'failed',
                        'reason_code' => 'in_flight',
                        'message' => 'This operation is already being processed. It will be retried.',
                    ]),
                ];
            }

            // A process died holding this claim. Take it over rather than
            // leaving the operation stuck for ever — atomically: two replays
            // arriving together both see an old claim, and only the one whose
            // UPDATE moves it on may run the work. Restamping created_at makes
            // the claim warm again, so the loser matches nothing.
            $won = SyncOperation::withoutGlobalScopes()
                ->whereKey($existing->getKey())
                ->where('status', 'processing')
                ->where('created_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
                ->update(['created_at' => now(), 'message' => 'Reclaimed from an abandoned attempt.']);

            return $won === 1
                ? ['replayed' => false, 'row' => $existing->refresh()]
                : $this->inFlight($operationId);
        }

        // A settled operation. Hand back the original answer verbatim — this
        // is what makes a retry safe (invariant I-2).
        $stored = $existing->result ?? [];

        // A different payload under the same operation id is either a client
        // bug or tampering. The first result stands; the mismatch is recorded.
        if ($existing->payload_hash !== null && $existing->payload_hash !== $payloadHash) {
            $stored['payload_mismatch'] = true;
        }

        $stored['status'] = $stored['status'] ?? $existing->status;

        // An answer that was not final — nothing was applied — is tried again
        // under the SAME id, so the device need not mint a new one and the
        // gate keeps working. Claimed atomically, as above: only the replay
        // whose UPDATE moves the row from its settled state runs the work.
        if (! isset($stored['payload_mismatch']) && in_array($existing->reason_code, self::RETRYABLE, true)) {
            $won = SyncOperation::withoutGlobalScopes()
                ->whereKey($existing->getKey())
                ->where('status', $existing->status)
                ->where('reason_code', $existing->reason_code)
                ->update([
                    'status' => 'processing',
                    'reason_code' => null,
                    'message' => 'Retrying: the earlier attempt did not complete.',
                    'created_at' => now(),
                ]);

            return $won === 1
                ? ['replayed' => false, 'row' => $existing->refresh()]
                : $this->inFlight($operationId);
        }

        return ['replayed' => true, 'result' => $this->decorate($operationId, $stored)];
    }

    /** @return array{replayed: bool, result: array<string,mixed>} */
    private function inFlight(string $operationId): array
    {
        return [
            'replayed' => true,
            'result' => $this->decorate($operationId, [
                'status' => 'failed',
                'reason_code' => 'in_flight',
                'message' => 'This operation is already being processed. It will be retried.',
            ]),
        ];
    }

    /** @param array<string,mixed> $outcome */
    private function settle(SyncOperation $row, array $outcome): void
    {
        $row->update([
            'status' => $outcome['status'] ?? 'failed',
            'reason_code' => $outcome['reason_code'] ?? null,
            'message' => $outcome['message'] ?? null,
            'server_id' => $outcome['server_id'] ?? null,
            'version' => $outcome['version'] ?? null,
            'result' => $outcome,
            'processed_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $outcome */
    private function decorate(string $operationId, array $outcome): array
    {
        return ['operation_id' => $operationId] + $outcome;
    }

    /** @return array<string,mixed> */
    private function refusal(string $code, string $message): array
    {
        return ['status' => 'rejected', 'reason_code' => $code, 'message' => $message];
    }

    /**
     * Free claims abandoned by a process that died mid-operation.
     *
     * Marked `failed`, not deleted: the operation id must stay taken, or a
     * replay would sail past the gate and apply the work twice.
     */
    public function reclaimStale(): int
    {
        return SyncOperation::withoutGlobalScopes()
            ->where('status', 'processing')
            ->where('created_at', '<', now()->subMinutes((int) config('offline.push.stale_claim_minutes', self::STALE_CLAIM_MINUTES)))
            ->update([
                'status' => 'failed',
                'reason_code' => 'abandoned',
                'message' => 'The server stopped while applying this. It is safe to retry.',
                'processed_at' => now(),
            ]);
    }

    /** Prune results past the replay window. Nothing else reads this table. */
    public function prune(): int
    {
        return SyncOperation::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays((int) config('offline.push.retention_days', self::RETENTION_DAYS)))
            ->delete();
    }
}
