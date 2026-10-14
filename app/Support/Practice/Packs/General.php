<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** The outpatient clinic every general practice is built around. */
final class General extends Pack
{
    public static function key(): string
    {
        return 'general';
    }

    public static function label(): string
    {
        return 'General & outpatient';
    }

    public static function icon(): string
    {
        return 'fa-user-doctor';
    }

    public static function summary(): string
    {
        return 'Consultations, dressings, injections and the everyday work of an outpatient clinic.';
    }

    public static function departments(): array
    {
        return [['Outpatient', 'OPD']];
    }

    public static function services(): array
    {
        return [
            ['General consultation', 20000],
            ['Specialist consultation', 50000],
            ['Follow-up visit', 15000],
            ['Wound dressing', 15000],
            ['Injection administration', 5000],
            ['Nebulization', 20000],
            ['ECG', 40000],
            ['Medical examination (school or employment)', 30000],
            ['Blood pressure check', 5000],
        ];
    }

    /** The bedside tests a clinic runs without a full laboratory. */
    public static function labTests(): array
    {
        return [
            ['Malaria RDT', 'Blood', '', 'Negative', 8000],
            ['Random blood sugar', 'Blood', 'mmol/L', '3.9–7.8', 8000],
            ['Urinalysis', 'Urine', '', '', 15000],
            ['Pregnancy test (HCG)', 'Urine', '', 'Negative', 8000],
            ['HIV test', 'Blood', '', 'Non-reactive', 10000],
            ['Haemoglobin estimation', 'Blood', 'g/dL', '12–16', 5000],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Consultation Room 1', 'consultation', 1],
            ['Consultation Room 2', 'consultation', 1],
            ['Triage / Vitals Room', 'consultation', 2],
            ['Minor Procedure Room', 'theatre', 2],
        ];
    }

    public static function visitReasons(): array
    {
        return ['New complaint', 'Medication refill', 'Injury', 'Medical examination'];
    }

    public static function complaints(): array
    {
        return [
            'Fever', 'Headache', 'Cough', 'Abdominal pain', 'Diarrhoea and vomiting',
            'Body weakness', 'Chest pain', 'Difficulty breathing', 'Joint pains',
        ];
    }

    public static function diagnoses(): array
    {
        return [
            'Malaria', 'Upper respiratory tract infection', 'Urinary tract infection', 'Gastroenteritis',
            'Peptic ulcer disease', 'Hypertension', 'Type 2 diabetes', 'Anaemia', 'Typhoid fever',
        ];
    }

    public static function remarks(): array
    {
        return ['Advised on fluids and rest'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => [
                'Wound dressing', 'Suture removal', 'Injection', 'IV fluids', 'Nebulisation',
                'Catheterisation', 'Incision and drainage', 'Plaster / splint',
            ],
        ];
    }
}
