<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/**
 * The long-term patients: HIV, TB, diabetes, hypertension. In East Africa
 * this is a large share of every clinic's returning visits, and it is its
 * own kind of work — reviews and refills on a schedule, not new complaints.
 */
final class ChronicCare extends Pack
{
    public static function key(): string
    {
        return 'chronic';
    }

    public static function label(): string
    {
        return 'Chronic care';
    }

    public static function icon(): string
    {
        return 'fa-heart-pulse';
    }

    public static function summary(): string
    {
        return 'Reviews and refills for HIV, TB, diabetes, hypertension and asthma.';
    }

    public static function departments(): array
    {
        return [['Chronic care clinic', 'CCC']];
    }

    public static function services(): array
    {
        return [
            ['Chronic care review', 20000],
            ['Diabetes review', 25000],
            ['Hypertension review', 20000],
            ['Adherence counselling', 10000],
            ['Diabetic foot assessment', 20000],
        ];
    }

    public static function labTests(): array
    {
        return [
            ['HbA1c', 'Blood', '%', '4.0–5.6', 45000],
            ['Fasting blood sugar', 'Blood', 'mmol/L', '3.9–5.6', 8000],
            ['Serum creatinine', 'Blood', 'µmol/L', '60–110', 15000],
            ['Lipid profile', 'Blood', 'mmol/L', '', 50000],
            ['CD4 count', 'Blood', 'cells/µL', '500–1500', 30000],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Metformin 500mg', 'Tablets', 'tablet', 1000, 60, 150, 200],
            ['Glibenclamide 5mg', 'Tablets', 'tablet', 500, 50, 150, 100],
            ['Amlodipine 5mg', 'Tablets', 'tablet', 1000, 60, 200, 200],
            ['Losartan 50mg', 'Tablets', 'tablet', 600, 150, 400, 120],
            ['Hydrochlorothiazide 25mg', 'Tablets', 'tablet', 600, 40, 120, 120],
            ['Atorvastatin 20mg', 'Tablets', 'tablet', 500, 200, 500, 100],
            ['Salbutamol inhaler', 'Consumables', 'inhaler', 40, 8000, 15000, 8],
            ['Soluble insulin 100 IU/ml', 'Injectables', 'vial', 30, 18000, 32000, 6],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Chronic care review', 'Drug refill', 'Viral load review'];
    }

    public static function complaints(): array
    {
        return ['Swollen feet', 'Frequent urination', 'Dizziness', 'Numbness in the feet'];
    }

    public static function diagnoses(): array
    {
        return ['HIV infection', 'Pulmonary tuberculosis', 'Asthma', 'Heart failure', 'Chronic kidney disease'];
    }

    public static function remarks(): array
    {
        return ['Continue current regimen and review in one month', 'Adherence counselling done'];
    }
}
