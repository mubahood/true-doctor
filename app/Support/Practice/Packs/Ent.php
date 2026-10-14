<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

final class Ent extends Pack
{
    public static function key(): string
    {
        return 'ent';
    }

    public static function label(): string
    {
        return 'Ear, nose & throat';
    }

    public static function icon(): string
    {
        return 'fa-ear-listen';
    }

    public static function summary(): string
    {
        return 'ENT consultations, hearing tests, ear care and ENT surgery.';
    }

    public static function departments(): array
    {
        return [['Ear, nose & throat', 'ENT']];
    }

    public static function services(): array
    {
        return [
            ['ENT consultation', 50000],
            ['Ear syringing', 15000],
            ['Ear wax removal (microsuction)', 40000],
            ['Foreign body removal (ear or nose)', 50000],
            ['Nasal packing', 50000],
            ['Audiometry (hearing test)', 60000],
            ['Tympanometry', 40000],
            ['Nasal endoscopy', 150000],
            ['Adenoidectomy', 1000000],
            ['Tonsillectomy', 1200000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Ear & nose drops', 'bottle']];
    }

    public static function stockItems(): array
    {
        return [
            ['Ciprofloxacin ear drops', 'Ear & nose drops', 'bottle', 40, 3000, 7000, 8],
            ['Wax-softening ear drops', 'Ear & nose drops', 'bottle', 40, 3500, 8000, 8],
            ['Xylometazoline nasal drops', 'Ear & nose drops', 'bottle', 40, 4000, 9000, 8],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['ENT Examination Room', 'consultation', 1],
            ['Audiology Booth', 'other', 1],
        ];
    }

    public static function complaints(): array
    {
        return ['Ear pain', 'Hearing loss', 'Ringing in the ears', 'Blocked nose', 'Sore throat', 'Nose bleeding', 'Hoarse voice'];
    }

    public static function diagnoses(): array
    {
        return [
            'Acute otitis media', 'Chronic suppurative otitis media', 'Impacted ear wax',
            'Allergic rhinitis', 'Sinusitis', 'Tonsillitis', 'Adenoid hypertrophy',
        ];
    }

    public static function orderTitles(): array
    {
        return ['procedure' => ['Ear syringing', 'Audiometry', 'Nasal packing', 'Foreign body removal']];
    }
}
