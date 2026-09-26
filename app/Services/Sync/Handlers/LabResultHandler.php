<?php

namespace App\Services\Sync\Handlers;

use App\Models\LabOrderItem;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\LabService;
use App\Services\Sync\EntityHandler;
use App\Support\SyncRevision;
use Illuminate\Support\Facades\Validator;

/**
 * A laboratory result reported from a device.
 *
 * The strictest strategy in the whole system, and deliberately so. The plan
 * (§10.1) puts it plainly: **a result overwritten by a stale device is a
 * patient-safety event.** Somebody is dosed on a potassium reading, or sent
 * home on a haemoglobin. So unlike patient demographics there is no merge, no
 * field-level reconciliation and no "the device is probably right":
 *
 *   base_version matches  → apply
 *   base_version stale    → REFUSE, and raise it for a person
 *
 * There is nothing to merge anyway. A result is one value with one flag, and
 * two benches reporting different numbers for the same specimen is not a
 * formatting disagreement — it is a question somebody has to answer with the
 * specimen in front of them.
 *
 * A result is also never CREATED from a device. The line exists because a
 * doctor ordered the test; the bench fills it in. An operation claiming to
 * create one is refused rather than quietly inventing work nobody ordered.
 */
class LabResultHandler extends EntityHandler
{
    public function __construct(private readonly LabService $lab) {}

    public function entity(): string
    {
        return 'lab_items';
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        // The same ability the bench worklist gates on. A role that can enter a
        // result at a microscope can enter one on a tablet beside it.
        return $actor->can('lab.process');
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        if ($operation !== 'update') {
            return $this->rejected(
                'unsupported_operation',
                'A result is filled in against a test somebody ordered. It cannot be created from a device.',
            );
        }

        $uuid = (string) ($payload['uuid'] ?? '');

        // lockForUpdate: two benches reporting the same specimen in the same
        // second must be serialised, or both read version 3 and both write 4,
        // and one result silently disappears.
        $item = LabOrderItem::where('uuid', $uuid)->lockForUpdate()->first();

        if ($item === null) {
            return $this->rejected(
                'not_found',
                'That test is not on this server. It may have been cancelled — sync and check.',
            );
        }

        // The bench worklist's own rules (LabResultRequest), so a device can
        // never store a flag or a length the web could not; a device reports
        // a value, so the value is required here.
        $rules = \App\Http\Requests\LabResultRequest::rulesFor();
        $rules['result_value'] = ['required', ...array_values(array_filter($rules['result_value'], fn ($r) => $r !== 'nullable'))];
        $errors = Validator::make($payload, $rules)->errors()->toArray();

        if ($errors !== []) {
            return $this->rejected('validation_failed', 'This result could not be saved as recorded.', $errors);
        }

        $order = $item->order;

        if ($order !== null && $order->status->isTerminal()) {
            return $this->rejected(
                'parent_closed',
                'That lab order is closed. A result cannot be added to it now.',
            );
        }

        $serverVersion = (int) ($item->version ?? 1);
        $baseVersion = $record->base_version;

        // Already reported by somebody else while this device was away.
        if ($baseVersion !== null && $baseVersion > 0 && $baseVersion !== $serverVersion) {
            return $this->conflict(
                // `manual` and nothing else: there is no safe automatic answer
                // to two different numbers for one specimen.
                'manual',
                $baseVersion,
                $serverVersion,
                ['result_value'],
                [],
                [
                    'result_value' => $item->result_value,
                    'result_flag' => $item->result_flag,
                    'resulted_at' => $item->resulted_at?->toIso8601String(),
                ],
            );
        }

        // A result that is already there and was NOT reported by this device is
        // refused even when the versions happen to agree — a row that predates
        // versioning has version 1 and a filled-in result, and overwriting it
        // because the numbers line up is exactly the failure this handler
        // exists to prevent.
        if ($item->result_value !== null
            && $baseVersion === null
            && $item->origin_operation_id !== $record->operation_id) {
            return $this->rejected(
                'already_reported',
                'A result is already recorded for this test. Reporting a different one has to be done '
                .'on the bench worklist, where both can be seen.',
            );
        }

        $this->lab->recordResult($item, [
            'result_value' => $payload['result_value'],
            'result_flag' => $payload['result_flag'] ?? null,
            'result_notes' => $payload['result_notes'] ?? null,
        ], $actor->id);

        $item->forceFill([
            'version' => $serverVersion + 1,
            'sync_revision' => SyncRevision::next(),
            'origin_device_id' => $record->device_id,
            'origin_operation_id' => $record->operation_id,
            'client_created_at' => $record->client_created_at,
        ])->saveQuietly();

        return $this->accepted($item->id, $serverVersion + 1, [
            // The time the SERVER stamped, so the device stops showing its own
            // guess at when the result was taken.
            'resulted_at' => $item->fresh()->resulted_at?->toIso8601String(),
        ]);
    }
}
