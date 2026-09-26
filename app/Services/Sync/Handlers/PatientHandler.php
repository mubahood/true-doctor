<?php

namespace App\Services\Sync\Handlers;

use App\Http\Requests\PatientRequest;
use App\Models\Patient;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\PatientService;
use App\Services\Sync\EntityHandler;
use App\Support\SyncRevision;
use Illuminate\Support\Facades\Validator;

/**
 * Patients arriving from a device.
 *
 * Two things make this the most interesting handler:
 *
 * **The number.** `patient_no` is allocated by `Support\Sequence` under a row
 * lock and cannot be minted on a device (invariant I-11). The device supplies
 * a UUID, which it keeps for life (I-8); the number is assigned here, inside
 * the operation's transaction, by the same `PatientService::register` the
 * online form calls. It comes back in `assigned` so the device can replace the
 * provisional label it has been showing.
 *
 * **The merge.** Demographics are the one offline entity where two people
 * genuinely edit the same row — reception updating a phone number while a
 * ward clerk fixes an address. Overwriting either would lose real work, so
 * fields changed by only one side are merged and a field both sides changed is
 * raised to a human (plan §10.1).
 */
class PatientHandler extends EntityHandler
{
    /**
     * Fields a device may edit, and which are therefore mergeable.
     *
     * Identity is not here: `patient_no`, `uuid` and `hospital_id` are
     * server-authoritative and `PatientService::update` already strips them.
     * `bank_details` is not here either — it is encrypted at rest and has no
     * business travelling to a device (plan §17, data minimisation).
     */
    private const MERGEABLE = [
        'first_name', 'last_name', 'dob', 'sex',
        'phone_1', 'phone_2', 'email', 'address', 'home_address', 'district_id',
        'blood_type', 'allergies', 'chronic_conditions',
        'spouse_name', 'father_name', 'mother_name',
        'emergency_contact_name', 'emergency_contact_phone',
        'notes', 'status',
        // The web form's insurance and consent fields, so a patient registered
        // on a device carries what one registered at the desk does.
        'insurance_provider', 'insurance_member_no', 'consent_given',
    ];

    /**
     * Fields where a disagreement is never merged automatically.
     *
     * A wrong date of birth changes drug dosing and a wrong allergy list can
     * kill somebody. When two devices disagree about one of these, the write is
     * refused and a person decides.
     */
    private const NEVER_AUTO_MERGE = ['dob', 'sex', 'blood_type', 'allergies', 'chronic_conditions'];

    public function __construct(private readonly PatientService $patients) {}

    public function entity(): string
    {
        return 'patients';
    }

    public function authorize(string $operation, array $payload, User $actor): bool
    {
        return match ($operation) {
            'create' => $actor->can('create', Patient::class),
            'update', 'delete' => $actor->can('viewAny', Patient::class),
            default => false,
        };
    }

    public function apply(string $operation, array $payload, SyncOperation $record, User $actor, array $meta = []): array
    {
        return match ($operation) {
            'create' => $this->create($payload, $record, $actor),
            'update' => $this->update($payload, $record, $actor, (array) ($meta['base_fields'] ?? [])),
            'delete' => $this->archive($payload, $actor),
            default => $this->rejected('unsupported_operation', "Patients cannot be {$operation}d from a device."),
        };
    }

    private function create(array $payload, SyncOperation $record, User $actor): array
    {
        $uuid = (string) ($payload['uuid'] ?? '');

        // The device may have been told "accepted" and lost the reply, then
        // sent a NEW operation id for the same record — a different failure
        // from a replay, and one the idempotency gate cannot see. The uuid is
        // what makes the record identifiable either way (invariant I-8).
        $existing = Patient::withTrashed()->where('uuid', $uuid)->first();

        if ($existing !== null) {
            return $this->accepted($existing->id, (int) ($existing->version ?? 1), [
                'patient_no' => $existing->patient_no,
            ]);
        }

        $data = $this->clean($payload);
        $errors = $this->validate($data, null);

        if ($errors !== null) {
            return $this->rejected('validation_failed', 'This patient could not be saved as recorded.', $errors);
        }

        try {
            // Through the Service, so the plan limit, the checksummed number
            // and the composite unique index all still apply.
            $patient = $this->patients->register($data + ['uuid' => $uuid], $actor->id);
        } catch (\DomainException|\RuntimeException $e) {
            return $this->rejected('refused', $e->getMessage());
        }

        $patient->forceFill([
            'version' => 1,
            'sync_revision' => SyncRevision::next(),
            'origin_device_id' => $record->device_id,
            'origin_operation_id' => $record->operation_id,
            'client_created_at' => $record->client_created_at,
        ])->saveQuietly();

        return $this->accepted($patient->id, 1, ['patient_no' => $patient->patient_no]);
    }

    /** @param array<string,mixed> $baseFields */
    private function update(array $payload, SyncOperation $record, User $actor, array $baseFields): array
    {
        $uuid = (string) ($payload['uuid'] ?? '');

        // lockForUpdate: two devices pushing an edit of the same patient in the
        // same second must be serialised, or both read version 3 and both
        // write version 4.
        $patient = Patient::where('uuid', $uuid)->lockForUpdate()->first();

        if ($patient === null) {
            return $this->rejected('not_found', 'That patient no longer exists here.');
        }

        if (! $actor->can('update', $patient)) {
            return $this->rejected('forbidden', 'You may not change this patient.');
        }

        $serverVersion = (int) ($patient->version ?? 1);
        $baseVersion = $record->base_version;

        $incoming = array_intersect_key($this->clean($payload), array_flip(self::MERGEABLE));

        // `base_version` 0 means this device has never had ANY version of this
        // record confirmed — which happens only when it created the record
        // itself and is editing it before (or during) its first sync. The only
        // writer is the device in front of us, so there is nothing to merge
        // against and no conflict to raise. Sending it down the merge path
        // marked every field contested and produced a conflict for a record
        // nobody else had touched.
        if ($baseVersion !== null && $baseVersion > 0 && $baseVersion !== $serverVersion) {
            return $this->merge($patient, $incoming, $baseFields, $baseVersion, $serverVersion);
        }

        $errors = $this->validate($incoming + ['uuid' => $uuid], $patient->id);

        if ($errors !== null) {
            return $this->rejected('validation_failed', 'This change could not be saved as recorded.', $errors);
        }

        $this->patients->update($patient, $incoming);

        $patient->forceFill([
            'version' => $serverVersion + 1,
            'sync_revision' => SyncRevision::next(),
        ])->saveQuietly();

        return $this->accepted($patient->id, $serverVersion + 1);
    }

    /**
     * Somebody edited this patient while the device was away.
     *
     * A proper three-way merge, with the three sides being: what the device
     * wants (`$incoming`), what the server holds (`$patient`), and what the
     * server last told this device the field held (`base_fields`, sent with
     * the operation).
     *
     * With all three, "did the server change this too?" is a fact rather than
     * a guess:
     *
     *   server === base  → only the device moved it   → merge
     *   server !== base  → both moved it              → contested
     *
     * The alternative — inferring from version numbers alone — has to assume
     * the worst whenever a version moved for a reason the sync ledger cannot
     * explain, which is every edit made through the online panel. That made a
     * single receptionist changing an address block every offline edit of that
     * patient, which is noise nobody would act on.
     *
     * A contested field that is clinically dangerous stops the whole write. A
     * contested field that is not lets the server's value stand and tells the
     * device which one lost.
     *
     * @param  array<string,mixed>  $baseFields
     */
    private function merge(Patient $patient, array $incoming, array $baseFields, int $baseVersion, int $serverVersion): array
    {
        $changedByDevice = [];

        foreach ($incoming as $field => $value) {
            // Left as it was confirmed: not the device's change, whatever the
            // server holds now. An allergy added on the web must not turn an
            // offline phone-number correction into a conflict.
            if (array_key_exists($field, $baseFields) && $this->same($value, $baseFields[$field])) {
                continue;
            }
            // Equal to the server already: the device is echoing a value it
            // never touched, and there is nothing to fight about.
            if (! $this->same($patient->{$field}, $value)) {
                $changedByDevice[$field] = $value;
            }
        }

        if ($changedByDevice === []) {
            // The device is simply behind. Accepted as a no-op so the operation
            // does not sit in the outbox for ever.
            return $this->accepted($patient->id, $serverVersion);
        }

        $contested = [];

        foreach (array_keys($changedByDevice) as $field) {
            if (! array_key_exists($field, $baseFields)) {
                // No base to compare against — an older client, or a field the
                // device has never had confirmed. Conservative: treat it as
                // contested rather than overwrite something unseen.
                $contested[] = $field;

                continue;
            }

            if (! $this->same($patient->{$field}, $baseFields[$field])) {
                $contested[] = $field;
            }
        }

        $dangerous = array_values(array_intersect($contested, self::NEVER_AUTO_MERGE));

        if ($dangerous !== []) {
            return $this->conflict(
                'manual',
                $baseVersion,
                $serverVersion,
                $dangerous,
                [],
                $this->safeServerState($patient, $dangerous),
            );
        }

        $merged = array_diff_key($changedByDevice, array_flip($contested));

        if ($merged !== []) {
            $errors = $this->validate($merged + ['uuid' => $patient->uuid], $patient->id);

            if ($errors !== null) {
                return $this->rejected('validation_failed', 'This change could not be saved as recorded.', $errors);
            }

            $this->patients->update($patient, $merged);
            $patient->forceFill([
                'version' => $serverVersion + 1,
                'sync_revision' => SyncRevision::next(),
            ])->saveQuietly();
        }

        if ($contested === []) {
            return $this->accepted($patient->id, (int) $patient->version);
        }

        return $this->conflict(
            'field_merge',
            $baseVersion,
            (int) $patient->version,
            $contested,
            array_keys($merged),
            $this->safeServerState($patient, $contested),
        );
    }

    private function archive(array $payload, User $actor): array
    {
        $patient = Patient::where('uuid', (string) ($payload['uuid'] ?? ''))->first();

        if ($patient === null) {
            return $this->accepted(null, 0); // already gone; nothing to do
        }

        if (! $actor->can('delete', $patient)) {
            return $this->rejected('forbidden', 'You may not archive this patient.');
        }

        $this->patients->archive($patient);

        return $this->accepted($patient->id, (int) ($patient->version ?? 1));
    }

    /** Only fields a device is allowed to send. Anything else is dropped
     *  rather than rejected — a newer client sending a field this server does
     *  not know about should not fail the whole operation. */
    private function clean(array $payload): array
    {
        $allowed = array_flip(array_merge(self::MERGEABLE, ['uuid']));

        return array_intersect_key($payload, $allowed);
    }

    /**
     * The same rules the online form uses — one source of truth (C10).
     *
     * `rules()` is an instance method on the FormRequest and carries no
     * request state, so it can be read directly; inventing a second rule set
     * for sync is how the two surfaces come to disagree about what a valid
     * patient is.
     *
     * Only the keys present are validated: an update sends the fields it
     * touched, and applying `required` to a partial payload would reject an
     * edit for not restating a name nobody changed.
     *
     * @return array<string,array<int,string>>|null
     */
    private function validate(array $data, ?int $ignoreId): ?array
    {
        $rules = (new PatientRequest)->rules();

        if ($ignoreId !== null) {
            $rules = array_intersect_key($rules, $data);
        }

        $validator = Validator::make($data, $rules);

        return $validator->fails() ? $validator->errors()->toArray() : null;
    }

    private function same(mixed $a, mixed $b): bool
    {
        // An enum-cast attribute (sex, status) is an object here and a string
        // in the payload. Casting the object to string threw, so every merge
        // of a patient with a recorded sex failed with a server error. Found
        // by the mobile app's end-to-end test.
        $a = $a instanceof \BackedEnum ? $a->value : $a;
        $b = $b instanceof \BackedEnum ? $b->value : $b;

        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        if ($a instanceof \DateTimeInterface) {
            return $a->format('Y-m-d') === (string) $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * What the server holds for the contested fields, so the device can show
     * both sides. Nothing beyond those fields travels back.
     *
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private function safeServerState(Patient $patient, array $fields): array
    {
        $state = [];

        foreach ($fields as $field) {
            $value = $patient->{$field};
            $state[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                $value instanceof \BackedEnum => $value->value,
                default => $value,
            };
        }

        return $state;
    }
}
