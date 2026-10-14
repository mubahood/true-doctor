<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** A dispensary worth opening with: the common drugs and consumables. */
final class Pharmacy extends Pack
{
    public static function key(): string
    {
        return 'pharmacy';
    }

    public static function label(): string
    {
        return 'Pharmacy';
    }

    public static function icon(): string
    {
        return 'fa-pills';
    }

    public static function summary(): string
    {
        return 'A dispensary stocked with the common drugs, fluids and consumables.';
    }

    public static function modules(): array
    {
        return ['pharmacy'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.pharmacy';
    }

    public static function departments(): array
    {
        return [['Pharmacy', 'PHM']];
    }

    public static function stockCategories(): array
    {
        return [
            ['Tablets', 'tablet'],
            ['Capsules', 'capsule'],
            ['Syrups', 'bottle'],
            ['Injectables', 'vial'],
            ['Infusions', 'bag'],
            ['Consumables', 'piece'],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Paracetamol 500mg', 'Tablets', 'tablet', 2000, 60, 150, 300],
            ['Ibuprofen 400mg', 'Tablets', 'tablet', 1200, 90, 220, 200],
            ['Amoxicillin 500mg', 'Capsules', 'capsule', 900, 250, 600, 150],
            ['Artemether/Lumefantrine 20/120mg', 'Tablets', 'tablet', 720, 400, 900, 120],
            ['Metronidazole 400mg', 'Tablets', 'tablet', 800, 110, 280, 150],
            ['Ciprofloxacin 500mg', 'Tablets', 'tablet', 600, 300, 700, 100],
            ['Cetirizine 10mg', 'Tablets', 'tablet', 500, 80, 200, 100],
            ['Omeprazole 20mg', 'Capsules', 'capsule', 600, 200, 500, 100],
            ['Ferrous sulphate + folic acid', 'Tablets', 'tablet', 1000, 70, 180, 200],
            ['Oral rehydration salts', 'Consumables', 'sachet', 400, 300, 800, 80],
            ['Amoxicillin syrup 125mg/5ml', 'Syrups', 'bottle', 120, 3500, 8000, 24],
            ['Paracetamol syrup 120mg/5ml', 'Syrups', 'bottle', 150, 2500, 6000, 30],
            ['Zinc sulphate 20mg', 'Tablets', 'tablet', 600, 90, 250, 120],
            ['Ceftriaxone 1g', 'Injectables', 'vial', 150, 3000, 7500, 30],
            ['Diclofenac 75mg/3ml', 'Injectables', 'vial', 200, 1200, 3000, 40],
            ['Hydrocortisone 100mg', 'Injectables', 'vial', 80, 4000, 9500, 20],
            ['Normal saline 0.9% 500ml', 'Infusions', 'bag', 200, 2500, 6000, 40],
            ['Ringer\'s lactate 500ml', 'Infusions', 'bag', 150, 2800, 6500, 30],
            ['Dextrose 5% 500ml', 'Infusions', 'bag', 120, 2600, 6200, 30],
            ['Examination gloves', 'Consumables', 'pair', 1500, 300, 700, 300],
            ['Syringe 5ml', 'Consumables', 'piece', 1000, 200, 500, 200],
            ['Cannula 18G', 'Consumables', 'piece', 300, 600, 1500, 60],
            ['Gauze roll', 'Consumables', 'roll', 250, 800, 2000, 50],
        ];
    }

    public static function rooms(): array
    {
        return [['Dispensary', 'pharmacy', 2]];
    }

    public static function visitReasons(): array
    {
        return ['Medication refill'];
    }

    public static function orderTitles(): array
    {
        return [
            'pharmacy' => ['Dispense prescription', 'Take-home medicines', 'Ward stock for this patient', 'Refill', 'Single dose now', 'Discharge medicines'],
        ];
    }
}
