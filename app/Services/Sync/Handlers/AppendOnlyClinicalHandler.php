<?php

namespace App\Services\Sync\Handlers;

use App\Http\Requests\MedicationAdministrationRequest;
use App\Http\Requests\NursingNoteRequest;
use App\Http\Requests\VitalRoundRequest;
use App\Models\Admission;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\VitalRound;
use App\Services\Sync\EntityHandler;
use App\Support\SyncRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * Vitals, nursing notes and medication administrations.
 *
 * These are the highest-value offline workflows in the hospital and the
 * easiest to sync safely, for one reason: **they are already append-only**.
 * `VitalRound`, `NursingNote` and `MedicationAdministration` all set
 * `UPDATED_AT = null` and are written once. Two devices recording two rounds
 * recorded two rounds; both are true, and there is nothing to merge, overwrite
 * or lose (plan §1.4, §10.1).
 *
 * So the only failure modes left are duplication — handled by the operation
 * ledger and, as a second net, by the row's own uuid — and referring to a
 * parent that does not exist, which is an ordering problem the outbox's
 * `depends_on` prevents and this handler reports honestly when it slips
 * through.
 *
 * One handler for three entities rather than three near-identical ones: the
 * shape is genuinely the same, and three copies would drift.
 */
class AppendOnlyClinicalHandler extends EntityHandler
{
    /**
     * @param  'vitals'|'nursing_notes'|'med_administrations'  $entity
     */
    public function __construct(private readonly string $entity) {}

    public function entity(): string
    {
        return $this->entity;
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        // Deliberately not gated on the operation type. An attempt to UPDATE a
        // bedside record is not a permission problem — the record is immutable
        // for everyone — and answering "forbidden" would send a nurse looking
        // for an access right that would not help. `apply()` says what is
        // actually true.
        //
        // The SAME abilities the online panels gate on — `visits.vitals` for a
        // vitals round (VisitPolicy) and `ipd.manage` for a bedside log
        // (AdmissionPolicy). Inventing a permission for the offline path would
        // mean a role that can do something on a tablet and not at a desk, or
        // the reverse, with nothing to say which was intended.
        return match ($this->entity) {
            'vitals' => $actor->can('visits.vitals') || $actor->can('ipd.manage'),
            'nursing_notes', 'med_administrations' => $actor->can('ipd.manage'),
        };
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        if ($operation !== 'create') {
            // There is no legitimate update: a correction is a new entry that
            // supersedes, never a rewrite of what was recorded at the bedside.
            return $this->rejected(
                'append_only',
                'This record cannot be changed. Record a new one that corrects it instead.',
            );
        }

        // Second net under the operation ledger. A device that was told
        // "accepted", lost the reply, and then re-queued the work under a NEW
        // operation id would slip past the ledger — the uuid is what catches it
        // (invariant I-8).
        $existing = $this->model()::where('uuid', (string) ($payload['uuid'] ?? ''))->first();

        if ($existing !== null) {
            return $this->accepted((int) $existing->getKey(), 1);
        }

        $errors = Validator::make($payload, $this->rules())->errors()->toArray();

        if ($errors !== []) {
            return $this->rejected('validation_failed', 'This record could not be saved as recorded.', $errors);
        }

        $parent = $this->resolveParent($payload);

        if ($parent === null) {
            // Almost always an ordering slip, not bad data. Saying so lets the
            // device retry once its parent has landed instead of showing a
            // clinician a message about a visit they can see on screen.
            return $this->rejected(
                'parent_missing',
                'The admission this belongs to has not reached the server yet. It will be sent again.',
            );
        }

        if (! $this->parentAcceptsWrites($parent)) {
            return $this->rejected('parent_closed', 'That admission is closed; nothing more can be recorded on it.');
        }

        $row = $this->model()::create($this->attributes($payload, $parent, $record, $actor));

        $row->forceFill([
            'sync_revision' => SyncRevision::next(),
            'origin_device_id' => $record->device_id,
            'origin_operation_id' => $record->operation_id,
            // What the DEVICE thought the time was. The row's own created_at
            // stays the server's clock, which is authority (plan §7.3).
            'client_created_at' => $record->client_created_at,
        ])->saveQuietly();

        return $this->accepted((int) $row->getKey(), 1);
    }

    /** @return class-string<Model> */
    private function model(): string
    {
        return match ($this->entity) {
            'vitals' => VitalRound::class,
            'nursing_notes' => NursingNote::class,
            'med_administrations' => MedicationAdministration::class,
        };
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        // The ward's own rules — the same FormRequests the web's inpatient
        // screens validate with — so a device can never record what the web
        // would refuse (a blood pressure of "high", a note eight times longer).
        $ids = [
            'uuid' => ['required', 'uuid'],
            'admission_uuid' => ['required', 'uuid'],
        ];

        return $ids + match ($this->entity) {
            'vitals' => VitalRoundRequest::rulesFor(),
            'nursing_notes' => NursingNoteRequest::rulesFor(),
            'med_administrations' => MedicationAdministrationRequest::rulesFor(),
        };
    }

    /**
     * The admission or visit this belongs to, by UUID.
     *
     * By UUID and not by id: the parent may itself have been created on this
     * device minutes ago, so the only identifier both sides agree on is the one
     * the device minted.
     */
    private function resolveParent(array $payload): ?Model
    {
        // All three of these are INPATIENT records: `vital_rounds.admission_id`
        // is NOT NULL, and a round belongs to a stay rather than to a visit.
        // Outpatient triage vitals are columns on the visit itself, written by
        // `VisitService::recordVitals` — a different shape with a different
        // handler, not this one wearing a disguise.
        if (empty($payload['admission_uuid'])) {
            return null;
        }

        return Admission::where('uuid', $payload['admission_uuid'])->first();
    }

    private function parentAcceptsWrites(Model $parent): bool
    {
        // A discharged patient's chart is closed to new bedside entries — the
        // same rule NursingRoundTest already holds the online path to.
        return ! ($parent instanceof Admission) || $parent->status->isActive();
    }

    /** @return array<string, mixed> */
    private function attributes(array $payload, Model $parent, SyncOperation $record, User $actor): array
    {
        $common = [
            'uuid' => $payload['uuid'],
            // The server's clock decides when this happened; the device's is
            // kept beside it as provenance.
            'created_at' => now(),
        ];

        return match ($this->entity) {
            'vitals' => $common + [
                'admission_id' => $parent->getKey(),
                'temperature' => $payload['temperature'] ?? null,
                'pulse' => $payload['pulse'] ?? null,
                'blood_pressure' => $payload['blood_pressure'] ?? null,
                'respiratory_rate' => $payload['respiratory_rate'] ?? null,
                'spo2' => $payload['spo2'] ?? null,
                'note' => $payload['note'] ?? null,
                'recorded_by' => $actor->id,
            ],
            'nursing_notes' => $common + [
                'admission_id' => $parent->getKey(),
                'note' => $payload['note'],
                'recorded_by' => $actor->id,
            ],
            'med_administrations' => $common + [
                'admission_id' => $parent->getKey(),
                'drug_name' => $payload['drug_name'],
                'dose' => $payload['dose'] ?? null,
                'route' => $payload['route'] ?? null,
                'status' => $payload['status'],
                'note' => $payload['note'] ?? null,
                'administered_by' => $actor->id,
            ],
        };
    }
}
