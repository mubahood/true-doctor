<?php

namespace App\Enums;

use App\Models\User;

/**
 * What a patient link can open (roadmap 05 §4). Each is a document the
 * hospital already prints; a link is that PDF, behind a code.
 *
 * Who may send one is who may print it: the same permission the staff PDF
 * route asks for.
 */
enum SharedDocumentType: string
{
    case LabResult = 'lab_result';
    case RadiologyReport = 'radiology_report';
    case DischargeSummary = 'discharge_summary';
    case Prescription = 'prescription';
    case DentalQuote = 'dental_quote';

    public function label(): string
    {
        return match ($this) {
            self::LabResult => 'Lab result',
            self::RadiologyReport => 'Radiology report',
            self::DischargeSummary => 'Discharge summary',
            self::Prescription => 'Prescription',
            self::DentalQuote => 'Treatment quote',
        };
    }

    /** May this person send (and withdraw) a link to this kind of document? */
    public function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return match ($this) {
            self::LabResult => $user->can('lab.view'),
            self::RadiologyReport => $user->can('radiology.view'),
            self::DischargeSummary => $user->can('ipd.view'),
            // Both are the clinical record (Staff and access, docs/rbac.md).
            self::Prescription => $user->can('visits.view') && $user->can('visits.clinical'),
            self::DentalQuote => $user->can('patients.view') && $user->can('visits.clinical') && ($user->can('treatments.view') || $user->can('visits.diagnose')),
        };
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $t) => $c + [$t->value => $t->label()], []);
    }
}
