<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** Children, from the newborn check to the sick child. */
final class Paediatrics extends Pack
{
    public static function key(): string
    {
        return 'paediatrics';
    }

    public static function label(): string
    {
        return 'Paediatrics';
    }

    public static function icon(): string
    {
        return 'fa-baby';
    }

    public static function summary(): string
    {
        return 'Sick-child visits, immunisation, growth monitoring and newborn care.';
    }

    public static function departments(): array
    {
        return [['Pediatrics', 'PED']];
    }

    public static function services(): array
    {
        return [
            ['Paediatric consultation', 30000],
            ['Newborn check-up', 15000],
            ['Child immunisation', 10000],
            ['Growth monitoring', 10000],
            ['Nutrition assessment', 15000],
            ['Neonatal phototherapy (per day)', 50000],
        ];
    }

    public static function labTests(): array
    {
        return [
            ['Blood slide for malaria (B/S)', 'Blood', '', 'No parasites seen', 5000],
            ['Complete blood count (CBC)', 'Blood', '', '', 25000],
            ['Sickling test', 'Blood', '', 'Negative', 10000],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Paracetamol syrup 120mg/5ml', 'Syrups', 'bottle', 150, 2500, 6000, 30],
            ['Amoxicillin syrup 125mg/5ml', 'Syrups', 'bottle', 120, 3500, 8000, 24],
            ['Oral rehydration salts', 'Consumables', 'sachet', 400, 300, 800, 80],
            ['Zinc sulphate 20mg', 'Tablets', 'tablet', 600, 90, 250, 120],
            ['Albendazole 400mg', 'Tablets', 'tablet', 300, 300, 800, 60],
            ['Vitamin A 200,000 IU', 'Capsules', 'capsule', 200, 200, 500, 40],
        ];
    }

    public static function rooms(): array
    {
        return [['Immunisation Room', 'consultation', 2]];
    }

    public static function wards(): array
    {
        return [
            ['Pediatric ward', 4, 35000],
            ['Neonatal unit', 2, 60000],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Immunisation', 'Growth monitoring', 'Sick child'];
    }

    public static function complaints(): array
    {
        return ['Fever in a child', 'Not feeding well', 'Persistent crying', 'Rash', 'Ear discharge'];
    }

    public static function diagnoses(): array
    {
        return ['Pneumonia', 'Acute watery diarrhoea', 'Malnutrition', 'Otitis media', 'Neonatal jaundice', 'Measles'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => ['Immunisation', 'Growth monitoring', 'Phototherapy'],
            'admission' => ['Admit — paediatric ward', 'Admit to the neonatal unit'],
        ];
    }
}
