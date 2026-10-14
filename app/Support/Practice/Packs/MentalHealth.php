<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

final class MentalHealth extends Pack
{
    public static function key(): string
    {
        return 'mental_health';
    }

    public static function label(): string
    {
        return 'Mental health';
    }

    public static function icon(): string
    {
        return 'fa-brain';
    }

    public static function summary(): string
    {
        return 'Psychiatry, counselling and therapy, addiction care and residential rehabilitation.';
    }

    public static function modules(): array
    {
        return ['pharmacy'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.mental_health';
    }

    public static function accounts(): array
    {
        return [['4087', 'Mental health services income', 'income', 'operating_income', 'revenue.mental_health']];
    }

    public static function departments(): array
    {
        return [['Psychiatry', 'PSY'], ['Counselling', 'CNS']];
    }

    public static function services(): array
    {
        return [
            ['Psychiatric assessment', 80000],
            ['Psychiatric review', 50000],
            ['Counselling session (individual)', 50000],
            ['Couples or family therapy session', 80000],
            ['Group therapy session', 30000],
            ['Psychological assessment', 150000],
            ['Child and adolescent assessment', 80000],
            ['Addiction assessment', 100000],
            ['Residential rehabilitation (per day)', 150000],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Fluoxetine 20mg', 'Capsules', 'capsule', 300, 150, 400, 60],
            ['Amitriptyline 25mg', 'Tablets', 'tablet', 500, 50, 150, 100],
            ['Haloperidol 5mg', 'Tablets', 'tablet', 300, 80, 250, 60],
            ['Olanzapine 10mg', 'Tablets', 'tablet', 300, 400, 1000, 60],
            ['Risperidone 2mg', 'Tablets', 'tablet', 300, 250, 700, 60],
            ['Carbamazepine 200mg', 'Tablets', 'tablet', 500, 100, 300, 100],
            ['Sodium valproate 500mg', 'Tablets', 'tablet', 300, 350, 900, 60],
            ['Diazepam 5mg', 'Tablets', 'tablet', 200, 50, 150, 40],
            ['Haloperidol decanoate 50mg/ml', 'Injectables', 'ampoule', 30, 6000, 15000, 6],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Counselling Room 1', 'consultation', 2],
            ['Counselling Room 2', 'consultation', 2],
            ['Group Therapy Room', 'other', 12],
        ];
    }

    public static function wards(): array
    {
        return [
            ['Psychiatric ward', 4, 60000],
            ['Rehabilitation unit', 6, 150000],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Counselling session', 'Psychiatric review', 'Medication review', 'Family meeting'];
    }

    public static function complaints(): array
    {
        return [
            'Low mood', 'Anxiety', 'Difficulty sleeping', 'Hearing voices', 'Alcohol use',
            'Drug use', 'Thoughts of self-harm', 'Stress',
        ];
    }

    public static function diagnoses(): array
    {
        return [
            'Depression', 'Generalised anxiety disorder', 'Bipolar affective disorder', 'Schizophrenia',
            'Alcohol use disorder', 'Substance use disorder', 'Post-traumatic stress disorder', 'Insomnia', 'Epilepsy',
        ];
    }

    public static function remarks(): array
    {
        return ['Safety plan discussed', 'Continue medication and review in two weeks', 'Referred for counselling'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => ['Counselling session', 'Group therapy', 'Psychological assessment'],
            'admission' => ['Admit — psychiatric ward', 'Admit to rehabilitation'],
        ];
    }
}
