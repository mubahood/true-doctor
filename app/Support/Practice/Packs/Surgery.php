<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** Theatre: from a suture in the procedure room to a laparotomy. */
final class Surgery extends Pack
{
    public static function key(): string
    {
        return 'surgery';
    }

    public static function label(): string
    {
        return 'Surgery & theatre';
    }

    public static function icon(): string
    {
        return 'fa-kit-medical';
    }

    public static function summary(): string
    {
        return 'Minor procedures, theatre, anaesthesia and the surgical ward.';
    }

    public static function modules(): array
    {
        return ['inpatient'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.procedures';
    }

    public static function departments(): array
    {
        return [['Surgery', 'SUR'], ['Theatre', 'THR']];
    }

    public static function services(): array
    {
        return [
            ['Surgical consultation', 50000],
            ['Minor suturing', 40000],
            ['Incision and drainage of an abscess', 50000],
            ['Circumcision', 80000],
            ['Lipoma excision', 250000],
            ['Minor surgery (day case)', 200000],
            ['Hernia repair (day case)', 600000],
            ['Appendicectomy', 1500000],
            ['Laparotomy', 2500000],
            ['Theatre fee (minor)', 100000],
            ['Theatre fee (major)', 400000],
            ['General anaesthesia', 300000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Surgical supplies', 'piece']];
    }

    public static function stockItems(): array
    {
        return [
            ['Surgical blade No. 11', 'Surgical supplies', 'piece', 200, 400, 1000, 40],
            ['Nylon suture 2/0', 'Surgical supplies', 'piece', 100, 3000, 7000, 20],
            ['Chromic catgut 2/0', 'Surgical supplies', 'piece', 100, 3500, 8000, 20],
            ['Sterile surgical gloves', 'Surgical supplies', 'pair', 300, 1200, 3000, 60],
            ['Lidocaine 2% 20ml', 'Injectables', 'vial', 60, 2500, 6000, 12],
            ['Povidone-iodine 10% 500ml', 'Consumables', 'bottle', 30, 9000, 18000, 6],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Main Theatre', 'theatre', 4],
            ['Recovery Room', 'ward', 2],
        ];
    }

    public static function wards(): array
    {
        return [['Surgical ward', 4, 45000]];
    }

    public static function complaints(): array
    {
        return ['Swelling', 'Groin swelling', 'Acute abdominal pain', 'Non-healing wound'];
    }

    public static function diagnoses(): array
    {
        return ['Inguinal hernia', 'Acute appendicitis', 'Abscess', 'Lipoma', 'Fracture'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => ['Minor surgery', 'Major surgery', 'Suturing', 'Circumcision', 'Post-operative review'],
            'admission' => ['Admit for surgery'],
        ];
    }
}
