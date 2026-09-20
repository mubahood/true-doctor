<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientDependent;
use App\Models\PatientInsurance;
use App\Support\CurrentHospital;
use App\Support\VerificationCode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns patient registration. The generated `patient_no` is unique per hospital
 * and checksummed; generation is serialized with a locking read inside the
 * transaction, and the composite unique index is the final guard against a
 * race (constraint F — no half-applied writes, no duplicate identities).
 */
class PatientService
{
    public function __construct(private readonly CurrentHospital $current) {}

    public function register(array $data, ?int $registeredBy = null): Patient
    {
        $hospitalId = $this->current->id();
        if ($hospitalId === null) {
            throw new RuntimeException('Cannot register a patient without a resolved hospital context.');
        }

        // Subscription plan may cap the number of patients (§21).
        app(\App\Support\PlanLimit::class)->assertCanCreate('patients');

        return $this->createWithNumber($data, $registeredBy, $hospitalId);
    }

    public function update(Patient $patient, array $data): Patient
    {
        // patient_no and hospital_id are never mutable through update.
        unset($data['patient_no'], $data['hospital_id'], $data['uuid']);
        $patient->update($data);

        return $patient->refresh();
    }

    /** Archive (soft-delete) a patient. */
    public function archive(Patient $patient): void
    {
        $patient->delete();
    }

    // ── Dependents / guardians ─────────────────────────────────

    /**
     * Link an existing patient of the same hospital as a dependent. Both
     * patients resolve through the tenant scope, so a cross-tenant link is
     * impossible; we only guard against self-links and duplicates here.
     *
     * @throws DomainException when the link would be a self-link or a duplicate
     */
    public function linkDependent(Patient $guardian, string $dependentUuid, string $relationship): PatientDependent
    {
        $dependent = Patient::where('uuid', $dependentUuid)->firstOrFail();

        if ($dependent->id === $guardian->id) {
            throw new DomainException('A patient cannot be their own dependent.');
        }

        $exists = PatientDependent::where('patient_id', $guardian->id)
            ->where('dependent_patient_id', $dependent->id)
            ->exists();

        if ($exists) {
            throw new DomainException("{$dependent->full_name} is already linked.");
        }

        $link = PatientDependent::create([
            'patient_id' => $guardian->id,
            'dependent_patient_id' => $dependent->id,
            'relationship' => $relationship,
            'status' => 'active',
        ]);

        // The caller needs the linked patient for its confirmation message;
        // it is already loaded, so hand it over rather than re-querying.
        $link->setRelation('dependent', $dependent);

        return $link;
    }

    /** Remove a guardian ⇄ dependent link that belongs to this guardian. */
    public function unlinkDependent(Patient $guardian, int $linkId): void
    {
        PatientDependent::where('patient_id', $guardian->id)->whereKey($linkId)->firstOrFail()->delete();
    }

    // ── Insurance coverage ─────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function addInsurance(Patient $patient, array $data): PatientInsurance
    {
        /** @var PatientInsurance */
        return $patient->insurances()->create($data);
    }

    /** Remove a coverage row that belongs to this patient. */
    public function removeInsurance(Patient $patient, int $insuranceId): void
    {
        $patient->insurances()->whereKey($insuranceId)->firstOrFail()->delete();
    }

    private function createWithNumber(array $data, ?int $registeredBy, int $hospitalId): Patient
    {
        $year = (int) date('Y');

        // Retry on the (extremely unlikely) unique collision after locking.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $registeredBy, $year) {
                    // Locking read serializes concurrent registrations for this
                    // hospital/year (global scope already filters to the tenant).
                    $seq = \App\Support\Sequence::next('patient', (string) $year, fn () => Patient::withTrashed()->whereYear('created_at', $year)->count());

                    $patient = new Patient($data);

                    // A uuid supplied by the caller is KEPT. A patient captured
                    // on a device is minted there and must arrive with the id it
                    // already has: the device has been showing it, other records
                    // captured in the same breath reference it, and a retry is
                    // only safe because the id identifies the same person both
                    // times (offline invariant I-8). Reassigning it here made
                    // every offline registration unmatchable and duplicated it on
                    // the next attempt.
                    //
                    // Validated, not trusted: a caller that sends nonsense gets a
                    // fresh one rather than a row with a broken key.
                    $supplied = $data['uuid'] ?? null;
                    $patient->uuid = is_string($supplied) && Str::isUuid($supplied)
                        ? $supplied
                        : (string) Str::uuid();
                    $patient->patient_no = $this->makeNumber($seq, $year);
                    $patient->registered_by = $registeredBy;
                    // Mirror the DB default in-memory so the returned model (and API
                    // responses built from it) never carry a null status.
                    $patient->status ??= \App\Enums\PatientStatus::Active;
                    if (! empty($data['consent_given'])) {
                        $patient->consent_at = now();
                    }
                    $patient->save();

                    return $patient;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to allocate a patient number.');
    }

    /** PT-<year>-<seq6><check>, e.g. PT-2026-000123K. */
    public function makeNumber(int $sequence, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $core = sprintf('PT-%d-%s', $year, str_pad((string) $sequence, 6, '0', STR_PAD_LEFT));

        return $core.VerificationCode::checkChar($core);
    }
}
