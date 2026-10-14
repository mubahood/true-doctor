<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/**
 * Eye care and the optical shop. Spectacles are STOCK, not services: a frame
 * and a pair of lenses are things on a shelf with a cost and a selling price,
 * and the margin on them is most of an optician's income.
 */
final class Eye extends Pack
{
    public static function key(): string
    {
        return 'eye';
    }

    public static function label(): string
    {
        return 'Eye care & optical';
    }

    public static function icon(): string
    {
        return 'fa-eye';
    }

    public static function summary(): string
    {
        return 'Eye tests, refraction, eye surgery, and an optical shop for frames and lenses.';
    }

    public static function modules(): array
    {
        return ['pharmacy'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.eye';
    }

    public static function accounts(): array
    {
        return [
            ['4080', 'Eye care & optical income', 'income', 'operating_income', 'revenue.eye'],
            ['6240', 'Lens laboratory orders', 'expense', 'operating_expense', null],
        ];
    }

    public static function departments(): array
    {
        return [['Ophthalmology', 'EYE'], ['Optical shop', 'OPT']];
    }

    public static function services(): array
    {
        return [
            ['Eye examination', 20000],
            ['Ophthalmologist consultation', 50000],
            ['Visual acuity test', 5000],
            ['Refraction', 30000],
            ['Tonometry (eye pressure)', 20000],
            ['Slit-lamp examination', 25000],
            ['Fundoscopy', 25000],
            ['Visual field test', 80000],
            ['OCT scan', 150000],
            ['Contact lens fitting', 60000],
            ['Foreign body removal (eye)', 40000],
            ['Pterygium excision', 400000],
            ['Cataract surgery (per eye)', 1500000],
            ['Glaucoma review', 30000],
        ];
    }

    public static function stockCategories(): array
    {
        return [
            ['Spectacle frames', 'piece'],
            ['Spectacle lenses', 'pair'],
            ['Contact lenses', 'pair'],
            ['Eye drops', 'bottle'],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Spectacle frame — standard', 'Spectacle frames', 'piece', 50, 30000, 80000, 10],
            ['Spectacle frame — designer', 'Spectacle frames', 'piece', 20, 80000, 200000, 4],
            ['Reading glasses (ready-made)', 'Spectacle frames', 'piece', 40, 8000, 25000, 8],
            ['Single-vision lenses', 'Spectacle lenses', 'pair', 50, 25000, 70000, 10],
            ['Bifocal lenses', 'Spectacle lenses', 'pair', 20, 45000, 120000, 4],
            ['Progressive lenses', 'Spectacle lenses', 'pair', 10, 120000, 300000, 2],
            ['Monthly contact lenses', 'Contact lenses', 'pair', 20, 30000, 70000, 4],
            ['Chloramphenicol 0.5% eye drops', 'Eye drops', 'bottle', 60, 2500, 6000, 12],
            ['Timolol 0.5% eye drops', 'Eye drops', 'bottle', 40, 6000, 15000, 8],
            ['Artificial tears', 'Eye drops', 'bottle', 50, 5000, 12000, 10],
            ['Dexamethasone-neomycin eye drops', 'Eye drops', 'bottle', 40, 4000, 10000, 8],
            ['Tropicamide 1% eye drops', 'Eye drops', 'bottle', 10, 15000, 25000, 2],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Eye Examination Room', 'consultation', 1],
            ['Refraction Room', 'consultation', 1],
            ['Optical Shop', 'pharmacy', 2],
            ['Eye Theatre', 'theatre', 2],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Eye test', 'Spectacles collection', 'Glaucoma review', 'Post-operative review'];
    }

    public static function complaints(): array
    {
        return [
            'Blurred vision', 'Red eye', 'Itchy eyes', 'Eye pain', 'Difficulty reading',
            'Watery eyes', 'Something in the eye',
        ];
    }

    public static function diagnoses(): array
    {
        return [
            'Myopia', 'Hypermetropia', 'Presbyopia', 'Astigmatism', 'Allergic conjunctivitis',
            'Bacterial conjunctivitis', 'Cataract', 'Primary open-angle glaucoma', 'Pterygium', 'Dry eye',
        ];
    }

    public static function remarks(): array
    {
        return ['Spectacles prescribed', 'Review in six months', 'Refer for cataract surgery'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => ['Refraction', 'Tonometry', 'Fundoscopy', 'Foreign body removal', 'Cataract surgery', 'Spectacle fitting'],
        ];
    }
}
