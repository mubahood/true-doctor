<?php

namespace App\Support\Clinical;

use App\Models\Patient;

/**
 * The red flags a dentist (or anyone about to cut, extract or inject) must
 * know before starting, ticked in the patient's medical history and shown on
 * the alert bar beside the allergies. Kept on the patient (`medical_flags`)
 * by CaseSheetService, so the alert bar reads them without a query.
 */
final class MedicalFlags
{
    /** @var array<string,string> */
    public const OPTIONS = [
        'bleeding' => 'Bleeding disorder or blood thinners',
        'heart' => 'Heart condition or valve',
        'diabetes' => 'Diabetes',
        'hypertension' => 'High blood pressure',
        'hiv_hepatitis' => 'HIV or hepatitis',
        'bisphosphonates' => 'Bisphosphonates',
        'epilepsy' => 'Epilepsy',
    ];

    /** Not in the checklist, but on the bar too: it changes X-rays and medicines. */
    public const PREGNANCY = ['pregnant' => 'Pregnant', 'breastfeeding' => 'Breastfeeding'];

    /**
     * On the alert bar every desk and bench sees, HIV or hepatitis is said as
     * the precaution it calls for, never as the diagnosis; the case sheet,
     * read only by clinicians, says what was ticked.
     */
    public const ON_THE_BAR = ['hiv_hepatitis' => 'Infection-control precautions'];

    /** @return list<string> the labels to show for a patient on the alert bar, in the list's order */
    public static function labels(?Patient $patient): array
    {
        $keys = $patient === null ? [] : array_values(array_filter((array) ($patient->medical_flags ?? []), 'is_string'));
        $labels = [];
        foreach (self::OPTIONS + self::PREGNANCY as $key => $label) {
            if (in_array($key, $keys, true)) {
                $labels[] = self::ON_THE_BAR[$key] ?? $label;
            }
        }

        return $labels;
    }
}
