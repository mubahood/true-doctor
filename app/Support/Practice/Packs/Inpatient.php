<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/**
 * Beds, and the nights in them. The specialty wards — maternity, children's,
 * surgical — come with their own packs, so a hospital that admits only one
 * kind of patient is not handed every kind of ward.
 */
final class Inpatient extends Pack
{
    public static function key(): string
    {
        return 'inpatient';
    }

    public static function label(): string
    {
        return 'Inpatient care';
    }

    public static function icon(): string
    {
        return 'fa-bed';
    }

    public static function summary(): string
    {
        return 'Admissions, wards and the nightly charge for a bed.';
    }

    public static function modules(): array
    {
        return ['inpatient'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.inpatient';
    }

    public static function departments(): array
    {
        return [['Inpatient', 'IPD']];
    }

    public static function services(): array
    {
        return [
            ['Admission fee', 20000],
            ['Nursing care (per day)', 20000],
            ['Doctor\'s ward round (per day)', 15000],
            ['Blood transfusion', 80000],
            ['Oxygen (per day)', 60000],
        ];
    }

    /** Tiered the way most run in practice: general cheapest, ICU and private dearest. */
    public static function wards(): array
    {
        return [
            ['General ward', 6, 30000],
            ['Private ward', 2, 100000],
            ['Intensive care unit (ICU)', 2, 200000],
            ['Isolation ward', 2, 50000],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Admission'];
    }

    public static function orderTitles(): array
    {
        return [
            'admission' => ['Admit for observation', 'Admit — general ward', 'Admit for IV treatment', 'Transfer to another ward'],
        ];
    }
}
