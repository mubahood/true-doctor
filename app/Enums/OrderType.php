<?php

namespace App\Enums;

/**
 * What kind of work an order is.
 *
 * The type decides which specialist record the order points at (see
 * docs/orders.md) and nothing else — billing never reads it, so a new type can
 * be added without touching the money path.
 */
enum OrderType: string
{
    case Consultation = 'consultation';
    case Lab = 'lab';
    case Imaging = 'imaging';
    case Pharmacy = 'pharmacy';
    case Procedure = 'procedure';
    case Admission = 'admission';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'Consultation',
            self::Lab => 'Lab test',
            self::Imaging => 'Imaging',
            self::Pharmacy => 'Pharmacy',
            self::Procedure => 'Procedure',
            self::Admission => 'Admission',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Consultation => 'fa-user-doctor',
            self::Lab => 'fa-flask',
            self::Imaging => 'fa-x-ray',
            self::Pharmacy => 'fa-pills',
            self::Procedure => 'fa-briefcase-medical',
            self::Admission => 'fa-bed',
        };
    }

    /** The permission that lets someone carry this kind of work out. */
    public function ability(): string
    {
        return match ($this) {
            self::Consultation => 'visits.diagnose',
            self::Lab => 'lab.process',
            self::Imaging => 'radiology.report',
            self::Pharmacy => 'pharmacy.dispense',
            self::Procedure => 'treatments.manage',
            self::Admission => 'ipd.manage',
        };
    }

    /** @return array<string, string> value => label, for a <select> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
