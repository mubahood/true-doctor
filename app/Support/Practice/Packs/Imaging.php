<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** X-ray, ultrasound and cross-sectional imaging. */
final class Imaging extends Pack
{
    public static function key(): string
    {
        return 'imaging';
    }

    public static function label(): string
    {
        return 'Imaging & radiology';
    }

    public static function icon(): string
    {
        return 'fa-x-ray';
    }

    public static function summary(): string
    {
        return 'X-ray, ultrasound, CT and MRI, with reporting.';
    }

    public static function modules(): array
    {
        return ['radiology'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.imaging';
    }

    /** A scanner's service contract is often an imaging centre's largest bill after salaries. */
    public static function accounts(): array
    {
        return [['6260', 'Equipment service contracts', 'expense', 'operating_expense', null]];
    }

    public static function departments(): array
    {
        return [['Radiology', 'RAD']];
    }

    public static function services(): array
    {
        return [
            ['Radiology report on outside images', 20000],
            ['Copy of images on CD', 10000],
            ['Contrast administration', 50000],
        ];
    }

    public static function radiologyStudies(): array
    {
        return [
            ['Chest X-ray', 'X-Ray', 'Chest', 40000],
            ['Abdominal ultrasound', 'Ultrasound', 'Abdomen', 50000],
            ['Obstetric ultrasound', 'Ultrasound', 'Pelvis', 50000],
            ['Pelvic X-ray', 'X-Ray', 'Pelvis', 45000],
            ['Skull X-ray', 'X-Ray', 'Head', 45000],
            ['CT scan — head', 'CT', 'Head', 300000],
            ['CT scan — abdomen', 'CT', 'Abdomen', 400000],
            ['MRI — brain', 'MRI', 'Head', 800000],
            ['Echocardiogram', 'Ultrasound', 'Heart', 120000],
            ['Mammogram', 'X-Ray', 'Breast', 100000],
            ['Limb X-ray', 'X-Ray', 'Limb', 40000],
            ['Lumbar spine X-ray', 'X-Ray', 'Spine', 50000],
            ['Pelvic ultrasound', 'Ultrasound', 'Pelvis', 50000],
            ['Breast ultrasound', 'Ultrasound', 'Breast', 60000],
            ['Neck / thyroid ultrasound', 'Ultrasound', 'Neck', 60000],
            ['Doppler ultrasound', 'Ultrasound', 'Vessels', 120000],
            ['CT scan — chest', 'CT', 'Chest', 400000],
            ['MRI — spine', 'MRI', 'Spine', 900000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Imaging supplies', 'piece']];
    }

    public static function stockItems(): array
    {
        return [
            ['X-ray film 14 × 17', 'Imaging supplies', 'sheet', 200, 4000, 8000, 40],
            ['Ultrasound gel 5L', 'Imaging supplies', 'bottle', 10, 25000, 45000, 2],
            ['Iohexol contrast 300mg/ml', 'Injectables', 'vial', 20, 45000, 90000, 4],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['X-ray Room', 'radiology', 1],
            ['Ultrasound Room', 'radiology', 1],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Imaging only', 'Referred for a scan'];
    }
}
