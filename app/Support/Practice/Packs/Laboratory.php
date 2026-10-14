<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** A full laboratory — the bench, not just the rapid tests a clinic runs. */
final class Laboratory extends Pack
{
    public static function key(): string
    {
        return 'laboratory';
    }

    public static function label(): string
    {
        return 'Laboratory';
    }

    public static function icon(): string
    {
        return 'fa-flask';
    }

    public static function summary(): string
    {
        return 'Haematology, chemistry, serology and microbiology, with sample collection.';
    }

    public static function modules(): array
    {
        return ['laboratory'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.laboratory';
    }

    /** Tests a small lab sends on rather than runs — a real cost, and a common one. */
    public static function accounts(): array
    {
        return [['6250', 'Reference laboratory fees', 'expense', 'operating_expense', null]];
    }

    public static function departments(): array
    {
        return [['Laboratory', 'LAB']];
    }

    public static function services(): array
    {
        return [
            ['Sample collection fee', 5000],
            ['Home sample collection', 30000],
            ['Specimen referral to a reference laboratory', 20000],
        ];
    }

    public static function labTests(): array
    {
        return [
            ['Complete blood count (CBC)', 'Blood', '', '', 25000],
            ['Malaria RDT', 'Blood', '', 'Negative', 8000],
            ['Random blood sugar', 'Blood', 'mmol/L', '3.9–7.8', 8000],
            ['Urinalysis', 'Urine', '', '', 15000],
            ['Widal test', 'Blood', '', '', 15000],
            ['HIV test', 'Blood', '', 'Non-reactive', 10000],
            ['Liver function test', 'Blood', '', '', 50000],
            ['Renal function test', 'Blood', '', '', 50000],
            ['Stool analysis', 'Stool', '', '', 12000],
            ['Pregnancy test (HCG)', 'Urine', '', 'Negative', 8000],
            ['Hepatitis B test', 'Blood', '', 'Non-reactive', 25000],
            ['Lipid profile', 'Blood', 'mmol/L', '', 50000],
            ['Blood grouping', 'Blood', '', '', 12000],
            ['Fasting blood sugar', 'Blood', 'mmol/L', '3.9–5.6', 8000],
            ['HbA1c', 'Blood', '%', '4.0–5.6', 45000],
            ['Erythrocyte sedimentation rate (ESR)', 'Blood', 'mm/hr', '0–20', 10000],
            ['C-reactive protein (CRP)', 'Blood', 'mg/L', '< 5', 25000],
            ['Serum electrolytes', 'Blood', 'mmol/L', '', 45000],
            ['Uric acid', 'Blood', 'µmol/L', '150–420', 15000],
            ['Thyroid stimulating hormone (TSH)', 'Blood', 'mIU/L', '0.4–4.0', 60000],
            ['Prostate specific antigen (PSA)', 'Blood', 'ng/mL', '< 4.0', 50000],
            ['H. pylori stool antigen', 'Stool', '', 'Negative', 25000],
            ['Brucella test', 'Blood', '', 'Negative', 15000],
            ['Hepatitis C test', 'Blood', '', 'Non-reactive', 25000],
            ['Syphilis test (VDRL / RPR)', 'Blood', '', 'Non-reactive', 8000],
            ['Sputum for AFB (TB)', 'Sputum', '', 'No AFB seen', 10000],
            ['Urine culture and sensitivity', 'Urine', '', 'No growth', 40000],
            ['Blood culture and sensitivity', 'Blood', '', 'No growth', 60000],
            ['Prothrombin time (PT/INR)', 'Blood', 'INR', '0.8–1.2', 35000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Reagents', 'unit'], ['Lab consumables', 'piece']];
    }

    public static function stockItems(): array
    {
        return [
            ['Malaria RDT kit', 'Reagents', 'kit', 300, 1800, 4000, 60],
            ['HIV rapid test kit', 'Reagents', 'kit', 200, 2500, 6000, 40],
            ['Urine dipsticks', 'Reagents', 'strip', 300, 400, 1000, 60],
            ['Glucose test strips', 'Reagents', 'strip', 300, 900, 2000, 60],
            ['EDTA vacutainer tubes', 'Lab consumables', 'piece', 500, 300, 700, 100],
            ['Specimen containers', 'Lab consumables', 'piece', 500, 250, 600, 100],
            ['Microscope slides', 'Lab consumables', 'piece', 500, 100, 300, 100],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Main Laboratory', 'lab', 4],
            ['Sample Collection Room', 'lab', 2],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Laboratory tests only', 'Referred for tests'];
    }
}
