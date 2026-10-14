<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** Rehabilitation: sessions booked in courses, not one-off visits. */
final class Physiotherapy extends Pack
{
    public static function key(): string
    {
        return 'physiotherapy';
    }

    public static function label(): string
    {
        return 'Physiotherapy & rehabilitation';
    }

    public static function icon(): string
    {
        return 'fa-person-walking';
    }

    public static function summary(): string
    {
        return 'Assessments, therapy sessions, electrotherapy, home visits and mobility aids.';
    }

    public static function incomeKey(): string
    {
        return 'revenue.rehab';
    }

    public static function accounts(): array
    {
        return [['4085', 'Physiotherapy & rehabilitation income', 'income', 'operating_income', 'revenue.rehab']];
    }

    public static function departments(): array
    {
        return [['Physiotherapy', 'PHY'], ['Occupational therapy', 'OT']];
    }

    public static function services(): array
    {
        return [
            ['Physiotherapy assessment', 40000],
            ['Physiotherapy session', 40000],
            ['Exercise therapy session', 35000],
            ['Manual therapy / massage', 40000],
            ['Electrotherapy (TENS)', 30000],
            ['Therapeutic ultrasound', 30000],
            ['Spinal traction', 30000],
            ['Chest physiotherapy', 30000],
            ['Stroke rehabilitation session', 45000],
            ['Paediatric therapy session (cerebral palsy)', 40000],
            ['Occupational therapy session', 40000],
            ['Home-visit physiotherapy', 80000],
            ['Walking aid fitting', 20000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Mobility aids', 'piece'], ['Supports & braces', 'piece']];
    }

    public static function stockItems(): array
    {
        return [
            ['Elbow crutches', 'Mobility aids', 'pair', 10, 60000, 110000, 2],
            ['Walking stick', 'Mobility aids', 'piece', 10, 20000, 40000, 2],
            ['Lumbar support belt', 'Supports & braces', 'piece', 10, 35000, 70000, 2],
            ['Cervical collar', 'Supports & braces', 'piece', 10, 15000, 35000, 2],
            ['Knee brace', 'Supports & braces', 'piece', 10, 40000, 80000, 2],
            ['Kinesiology tape', 'Supports & braces', 'roll', 20, 12000, 25000, 4],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Physiotherapy Gym', 'other', 6],
            ['Treatment Cubicle 1', 'consultation', 1],
            ['Treatment Cubicle 2', 'consultation', 1],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Physiotherapy session', 'Rehabilitation review', 'Rehabilitation after surgery'];
    }

    public static function complaints(): array
    {
        return ['Low back pain', 'Neck pain', 'Knee pain', 'Shoulder pain', 'Weakness after a stroke', 'Stiff joints', 'Sports injury'];
    }

    public static function diagnoses(): array
    {
        return [
            'Mechanical low back pain', 'Cervical spondylosis', 'Knee osteoarthritis', 'Frozen shoulder',
            'Post-stroke hemiplegia', 'Cerebral palsy', 'Ankle sprain', 'Lumbar disc prolapse',
        ];
    }

    public static function remarks(): array
    {
        return ['Home exercise programme given', 'Continue sessions twice a week', 'Review progress in two weeks'];
    }

    public static function orderTitles(): array
    {
        return ['procedure' => ['Physiotherapy session', 'Exercise therapy', 'Electrotherapy', 'Manual therapy', 'Gait training']];
    }
}
