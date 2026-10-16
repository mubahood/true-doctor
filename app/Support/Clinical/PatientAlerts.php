<?php

namespace App\Support\Clinical;

use App\Enums\PatientStatus;
use App\Models\Patient;

/**
 * What every clinician must see before acting on a patient, wherever they act
 * (a visit, a stay, a lab or imaging order, a dispensation): allergies first,
 * then long-term conditions, then a deceased status. And, just as important,
 * when nobody has recorded whether the patient has allergies at all, because
 * "none shown" must never be read as "none".
 */
final class PatientAlerts
{
    /**
     * For an API payload: the same block, or null when there is no patient.
     *
     * @return array{allergies: list<string>, conditions: list<string>, deceased: bool, allergies_recorded: bool}|null
     */
    public static function forPayload(?Patient $patient): ?array
    {
        return $patient === null ? null : self::for($patient);
    }

    /**
     * @return array{allergies: list<string>, conditions: list<string>, deceased: bool, allergies_recorded: bool}
     */
    public static function for(Patient $patient): array
    {
        $clean = fn ($items) => array_values(array_filter(array_map(fn ($i) => trim((string) $i), (array) ($items ?? [])), fn ($i) => $i !== ''));
        $allergies = $clean($patient->allergies);

        // "None", "NKDA", "No known allergies" are a record of no allergies, not an allergy.
        $none = array_filter($allergies, fn ($a) => preg_match('/^(none|nil|nkda|nka|no known( drug)? allerg(y|ies))\.?$/i', $a) === 1);

        return [
            'allergies' => array_values(array_diff($allergies, $none)),
            'conditions' => $clean($patient->chronic_conditions),
            'deceased' => $patient->status === PatientStatus::Deceased,
            'allergies_recorded' => $patient->allergies !== null,
        ];
    }
}
