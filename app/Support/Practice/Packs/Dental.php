<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/**
 * A dental practice. Dental X-rays are SERVICES here rather than imaging
 * studies: a practice takes them at the chair and charges for them like any
 * other procedure, and it should not have to run a radiology worklist to do
 * so.
 *
 * The few drugs a dentist dispenses are here too, so a practice that does not
 * add the pharmacy pack still has them.
 */
final class Dental extends Pack
{
    public static function key(): string
    {
        return 'dental';
    }

    public static function label(): string
    {
        return 'Dental';
    }

    public static function icon(): string
    {
        return 'fa-tooth';
    }

    public static function summary(): string
    {
        return 'Fillings, extractions, root canals, crowns, dentures, orthodontics and dental materials.';
    }

    public static function modules(): array
    {
        return ['pharmacy'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.dental';
    }

    /** Crowns and dentures are made by a dental laboratory; most practices pay one per case. */
    public static function accounts(): array
    {
        return [
            ['4070', 'Dental income', 'income', 'operating_income', 'revenue.dental'],
            ['6230', 'Dental laboratory fees', 'expense', 'operating_expense', null],
            ['6231', 'Dental materials (not stocked)', 'expense', 'operating_expense', null],
        ];
    }

    public static function departments(): array
    {
        return [['Dental', 'DEN'], ['Orthodontics', 'ORTH']];
    }

    public static function services(): array
    {
        return [
            ['Dental check-up', 20000],
            ['Emergency dental visit', 40000],
            ['Dental X-ray (periapical)', 20000],
            ['Panoramic X-ray (OPG)', 60000],
            ['Scaling and polishing', 50000],
            ['Deep cleaning (per quadrant)', 80000],
            ['Fluoride application', 25000],
            ['Fissure sealant (per tooth)', 30000],
            ['Temporary filling', 30000],
            ['Dental filling', 60000],
            ['Composite (tooth-coloured) filling', 90000],
            ['Tooth extraction', 40000],
            ['Surgical extraction', 150000],
            ['Impacted wisdom tooth removal', 300000],
            ['Incision and drainage (dental abscess)', 60000],
            ['Root canal treatment', 250000],
            ['Root canal treatment — molar', 400000],
            ['Crown (porcelain fused to metal)', 600000],
            ['Partial denture (acrylic)', 350000],
            ['Complete denture (per arch)', 800000],
            ['Denture repair', 80000],
            ['Tooth whitening', 150000],
            ['Orthodontic consultation', 50000],
            ['Fixed braces (full treatment)', 3500000],
            ['Braces adjustment', 60000],
            ['Retainer', 300000],
        ];
    }

    public static function stockCategories(): array
    {
        return [
            ['Dental materials', 'unit'],
            ['Local anaesthetics', 'cartridge'],
            ['Dental consumables', 'piece'],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Lidocaine 2% with adrenaline cartridge', 'Local anaesthetics', 'cartridge', 300, 1500, 3500, 60],
            ['Articaine 4% cartridge', 'Local anaesthetics', 'cartridge', 150, 2500, 5000, 30],
            ['Composite resin syringe', 'Dental materials', 'syringe', 20, 60000, 120000, 4],
            ['Glass ionomer cement', 'Dental materials', 'pack', 10, 90000, 180000, 2],
            ['Calcium hydroxide liner', 'Dental materials', 'pack', 10, 35000, 70000, 2],
            ['Endodontic files (assorted)', 'Dental materials', 'pack', 20, 25000, 50000, 4],
            ['Gutta-percha points', 'Dental materials', 'box', 15, 18000, 36000, 3],
            ['Alginate impression material', 'Dental materials', 'pack', 10, 30000, 60000, 2],
            ['Prophylaxis paste', 'Dental materials', 'jar', 10, 20000, 40000, 2],
            ['Saliva ejectors', 'Dental consumables', 'piece', 500, 150, 500, 100],
            ['Cotton rolls', 'Dental consumables', 'pack', 50, 3000, 6000, 10],
            ['Face masks', 'Dental consumables', 'piece', 1000, 150, 500, 200],
            ['Examination gloves', 'Consumables', 'pair', 1500, 300, 700, 300],
            ['Chlorhexidine mouthwash 0.2%', 'Syrups', 'bottle', 60, 6000, 14000, 12],
            ['Amoxicillin 500mg', 'Capsules', 'capsule', 900, 250, 600, 150],
            ['Metronidazole 400mg', 'Tablets', 'tablet', 800, 110, 280, 150],
            ['Ibuprofen 400mg', 'Tablets', 'tablet', 1200, 90, 220, 200],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Dental Chair 1', 'consultation', 1],
            ['Dental Chair 2', 'consultation', 1],
            ['Dental X-ray Room', 'radiology', 1],
            ['Sterilisation Room', 'other', 1],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Toothache', 'Dental check-up', 'Cleaning', 'Braces review', 'Denture fitting', 'Continue root canal treatment'];
    }

    public static function complaints(): array
    {
        return [
            'Toothache', 'Swollen gum', 'Bleeding gums', 'Tooth sensitivity', 'Broken tooth',
            'Loose tooth', 'Bad breath', 'Jaw pain',
        ];
    }

    public static function diagnoses(): array
    {
        return [
            'Dental caries', 'Reversible pulpitis', 'Irreversible pulpitis', 'Periapical abscess',
            'Gingivitis', 'Chronic periodontitis', 'Impacted wisdom tooth', 'Dentine hypersensitivity', 'Malocclusion',
        ];
    }

    public static function remarks(): array
    {
        return [
            'Oral hygiene instructions given', 'Avoid hot drinks for 24 hours',
            'Return to complete root canal treatment', 'Review in one week',
        ];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => [
                'Extraction', 'Filling', 'Scaling and polishing', 'Root canal treatment (first stage)',
                'Root canal treatment (completion)', 'Impression for crown or denture', 'Crown fitting', 'Braces adjustment',
            ],
        ];
    }
}
