<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

final class Dermatology extends Pack
{
    public static function key(): string
    {
        return 'dermatology';
    }

    public static function label(): string
    {
        return 'Skin & aesthetics';
    }

    public static function icon(): string
    {
        return 'fa-hand-dots';
    }

    public static function summary(): string
    {
        return 'Dermatology, skin procedures and aesthetic treatments, with skin-care products.';
    }

    public static function modules(): array
    {
        return ['pharmacy'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.skin';
    }

    public static function accounts(): array
    {
        return [['4089', 'Skin & aesthetic income', 'income', 'operating_income', 'revenue.skin']];
    }

    public static function departments(): array
    {
        return [['Dermatology', 'DER']];
    }

    public static function services(): array
    {
        return [
            ['Dermatology consultation', 60000],
            ['Skin biopsy', 150000],
            ['Cryotherapy (warts)', 80000],
            ['Wart or skin tag removal', 80000],
            ['Keloid injection', 80000],
            ['Patch testing', 100000],
            ['Acne treatment session', 70000],
            ['Chemical peel', 150000],
            ['Microneedling session', 250000],
            ['Laser hair removal (per session)', 200000],
            ['Hair loss treatment session', 100000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Creams & ointments', 'tube'], ['Skin-care products', 'bottle']];
    }

    public static function stockItems(): array
    {
        return [
            ['Hydrocortisone 1% cream', 'Creams & ointments', 'tube', 60, 2500, 6000, 12],
            ['Clotrimazole 1% cream', 'Creams & ointments', 'tube', 60, 2000, 5000, 12],
            ['Benzoyl peroxide 5% gel', 'Creams & ointments', 'tube', 40, 8000, 18000, 8],
            ['Calamine lotion', 'Skin-care products', 'bottle', 40, 3000, 7000, 8],
            ['Sunscreen SPF 50', 'Skin-care products', 'bottle', 30, 25000, 55000, 6],
            ['Ketoconazole 2% shampoo', 'Skin-care products', 'bottle', 30, 12000, 25000, 6],
            ['Triamcinolone 40mg/ml', 'Injectables', 'vial', 20, 12000, 25000, 4],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Dermatology Consultation Room', 'consultation', 1],
            ['Skin Procedure Room', 'theatre', 1],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Skin problem', 'Aesthetic treatment', 'Treatment session'];
    }

    public static function complaints(): array
    {
        return ['Itchy skin', 'Rash', 'Acne', 'Skin discolouration', 'Hair loss', 'Warts', 'Skin lump'];
    }

    public static function diagnoses(): array
    {
        return [
            'Eczema', 'Acne vulgaris', 'Tinea (fungal infection)', 'Psoriasis', 'Urticaria',
            'Scabies', 'Vitiligo', 'Keloid', 'Melasma',
        ];
    }

    public static function orderTitles(): array
    {
        return ['procedure' => ['Cryotherapy', 'Skin biopsy', 'Chemical peel', 'Keloid injection']];
    }
}
