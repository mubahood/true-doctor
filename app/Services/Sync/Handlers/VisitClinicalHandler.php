<?php

namespace App\Services\Sync\Handlers;

use App\Enums\VisitStage;
use App\Http\Requests\VisitClinicalRequest;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\Visit;
use App\Services\Sync\EntityHandler;
use App\Services\VisitService;
use App\Support\SyncRevision;
use Illuminate\Support\Facades\Validator;

/**
 * A doctor's clinical narrative, written away from a desk.
 *
 * The narrative lives on the visit row — `complaints`, `diagnosis`,
 * `doctor_remarks` — so this is an UPDATE of a versioned record and not an
 * append, which makes it the second three-way merge in the system after
 * demographics. It is also the stricter of the two, and the difference is the
 * point of this class:
 *
 * **Every field here is on the never-auto-merge list.** For demographics,
 * merging a phone number one side changed and an address the other side
 * changed is obviously right — they are independent facts about a person. A
 * diagnosis is not an independent fact. Two clinicians writing different
 * diagnoses for one visit is a disagreement about the patient, and silently
 * keeping one of them — or worse, keeping one field from each — would
 * manufacture a clinical record that neither person wrote.
 *
 * So the merge still runs, and it still does the useful half: a field only the
 * device moved is applied, and a field the server moved too is raised for a
 * person. What never happens is a contested clinical sentence being decided by
 * a rule.
 *
 * **A visit is never OPENED from a device.** `visit_no` comes from
 * `Support\Sequence` under a row lock and a provisional number that later
 * changes actively misleads whoever wrote it down (invariant I-11), and
 * opening a visit bills a consultation. A device fills in a visit somebody
 * else opened.
 */
class VisitClinicalHandler extends EntityHandler
{
    /**
     * The narrative, and nothing else.
     *
     * Not `doctor_user_id` or `department_id` — reassigning a visit is an
     * administrative act with a queue behind it, not something to do from a
     * ward round. Not the stage or status either: those move through
     * `VisitService::transition`, which checks gates a device cannot see.
     */
    private const NARRATIVE = ['complaints', 'diagnosis', 'doctor_remarks'];

    public function __construct(private readonly VisitService $visits) {}

    public function entity(): string
    {
        return 'visits';
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        // The same ability the online clinical panel gates on. Inventing an
        // offline-only permission would mean a role that can diagnose on a
        // tablet and not at a desk, with nothing to say which was intended.
        return $actor->can('visits.diagnose');
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        if ($operation !== 'update') {
            return $this->rejected(
                'unsupported_operation',
                'A visit is opened at reception, where it gets its number and its consultation charge. '
                .'A device fills one in; it cannot start one.',
            );
        }

        $uuid = (string) ($payload['uuid'] ?? '');

        // lockForUpdate for the same reason as everywhere else here: two
        // devices writing the same visit in the same second must be
        // serialised, or both read version 3 and both write 4.
        $visit = Visit::where('uuid', $uuid)->lockForUpdate()->first();

        if ($visit === null) {
            return $this->rejected('not_found', 'That visit is not on this server. Sync and check.');
        }

        if (! $actor->can('diagnose', $visit)) {
            return $this->rejected('forbidden', 'You may not write on this visit.');
        }

        // Past Ongoing the visit has been billed, and in Completed it is
        // finished. Amending the narrative underneath an invoice somebody has
        // already been shown is not something to do silently from a device —
        // it is done in the panel, where the bill is visible.
        if ($visit->stage !== VisitStage::Ongoing) {
            return $this->rejected(
                'parent_closed',
                'That visit has moved on to billing. Its notes are amended in the panel, where the bill can be seen.',
            );
        }

        $incoming = array_intersect_key($payload, array_flip(self::NARRATIVE));

        if ($incoming === []) {
            return $this->rejected('validation_failed', 'There was nothing in this note to save.');
        }

        $serverVersion = (int) ($visit->version ?? 1);
        $baseVersion = $record->base_version;
        $baseFields = (array) ($meta['base_fields'] ?? []);

        if ($baseVersion !== null && $baseVersion > 0 && $baseVersion !== $serverVersion) {
            return $this->merge($visit, $incoming, $baseFields, $baseVersion, $serverVersion);
        }

        return $this->write($visit, $incoming, $serverVersion);
    }

    /**
     * Somebody wrote on this visit while the device was away.
     *
     * The three sides are the same as for demographics: what the device wants,
     * what the server holds, and what the server last CONFIRMED to this device
     * (`base_fields`). `server === base` means only the device moved the
     * field, so it can be applied; `server !== base` means both moved it.
     *
     * The difference from `PatientHandler` is what happens next. There, a
     * contested field that is not clinically dangerous lets the server's value
     * stand. Here every field is clinically dangerous, so a contested one is
     * always raised — and the fields that are NOT contested are still written,
     * because a doctor who added remarks to a visit whose diagnosis somebody
     * else corrected should not lose the remarks over it.
     *
     * @param  array<string,mixed>  $incoming
     * @param  array<string,mixed>  $baseFields
     */
    private function merge(Visit $visit, array $incoming, array $baseFields, int $baseVersion, int $serverVersion): array
    {
        $changed = [];

        foreach ($incoming as $field => $value) {
            // A field the device left as it was confirmed is not the device's
            // change: a remark added on the web while the device wrote the
            // diagnosis is somebody else's work, not a disagreement.
            if (array_key_exists($field, $baseFields) && $this->same($value, $baseFields[$field])) {
                continue;
            }
            if (! $this->same($visit->{$field}, $value)) {
                $changed[$field] = $value;
            }
        }

        if ($changed === []) {
            // The device is simply behind and is echoing what is already
            // there. Accepted as a no-op rather than left in the outbox.
            return $this->accepted($visit->id, $serverVersion);
        }

        $contested = [];

        foreach (array_keys($changed) as $field) {
            // No base for this field — an older client, or one that has never
            // had the field confirmed. Conservative: contested, rather than
            // overwriting a clinical sentence it has never seen.
            if (! array_key_exists($field, $baseFields) || ! $this->same($visit->{$field}, $baseFields[$field])) {
                $contested[] = $field;
            }
        }

        $uncontested = array_diff_key($changed, array_flip($contested));

        if ($uncontested !== []) {
            $result = $this->write($visit, $uncontested, $serverVersion);

            if (($result['status'] ?? null) !== 'accepted') {
                return $result;
            }
        }

        if ($contested === []) {
            return $this->accepted($visit->id, (int) $visit->fresh()->version);
        }

        return $this->conflict(
            // `manual`, never `field_merge`: there is no safe automatic answer
            // to two clinicians writing different diagnoses for one visit.
            'manual',
            $baseVersion,
            (int) $visit->fresh()->version,
            $contested,
            array_keys($uncontested),
            $this->serverState($visit->fresh(), $contested),
        );
    }

    /** @param array<string,mixed> $fields */
    private function write(Visit $visit, array $fields, int $serverVersion): array
    {
        $rules = array_intersect_key(VisitClinicalRequest::rulesFor(), $fields);
        $validator = Validator::make($fields, $rules);

        if ($validator->fails()) {
            return $this->rejected(
                'validation_failed',
                'This note could not be saved as recorded.',
                $validator->errors()->toArray(),
            );
        }

        // Through the Service, so sync cannot hold a different idea of what
        // writing a narrative means from the panel that does it online.
        $this->visits->updateClinical($visit, $fields);

        $visit->forceFill([
            'version' => $serverVersion + 1,
            'sync_revision' => SyncRevision::next(),
        ])->saveQuietly();

        return $this->accepted($visit->id, $serverVersion + 1);
    }

    private function same(mixed $a, mixed $b): bool
    {
        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    /**
     * What the server holds for the contested fields, so a person can read
     * both and choose. Nothing beyond those fields travels back — a conflict
     * record is not a second copy of the chart.
     *
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private function serverState(Visit $visit, array $fields): array
    {
        $state = [];

        foreach ($fields as $field) {
            $state[$field] = $visit->{$field};
        }

        return $state;
    }
}
