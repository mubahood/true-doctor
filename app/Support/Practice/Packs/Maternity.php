<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** Antenatal care to delivery, family planning and gynaecology. */
final class Maternity extends Pack
{
    public static function key(): string
    {
        return 'maternity';
    }

    public static function label(): string
    {
        return 'Maternity & gynaecology';
    }

    public static function icon(): string
    {
        return 'fa-person-pregnant';
    }

    public static function summary(): string
    {
        return 'Antenatal care, deliveries, family planning and gynaecology.';
    }

    public static function modules(): array
    {
        return ['inpatient', 'laboratory'];
    }

    public static function incomeKey(): string
    {
        return 'revenue.maternity';
    }

    public static function accounts(): array
    {
        return [['4060', 'Maternity & delivery income', 'income', 'operating_income', 'revenue.maternity']];
    }

    public static function departments(): array
    {
        return [['Maternity', 'MAT'], ['Antenatal clinic', 'ANC']];
    }

    public static function services(): array
    {
        return [
            ['Antenatal visit', 20000],
            ['Antenatal profile (first visit)', 60000],
            ['Postnatal check-up', 15000],
            ['Normal delivery', 300000],
            ['Assisted delivery (vacuum)', 450000],
            ['Caesarean section', 1200000],
            ['Pap smear', 30000],
            ['Cervical cancer screening (VIA)', 15000],
            ['Family planning counselling', 10000],
            ['IUD insertion', 60000],
            ['Contraceptive implant insertion', 40000],
            ['Injectable contraceptive', 10000],
            ['Gynaecological consultation', 40000],
            ['Manual vacuum aspiration (MVA)', 150000],
        ];
    }

    public static function labTests(): array
    {
        return [
            ['Haemoglobin estimation', 'Blood', 'g/dL', '11–16', 5000],
            ['Blood grouping', 'Blood', '', '', 12000],
            ['Syphilis test (VDRL / RPR)', 'Blood', '', 'Non-reactive', 8000],
            ['Urinalysis', 'Urine', '', '', 15000],
            ['HIV test', 'Blood', '', 'Non-reactive', 10000],
            ['Hepatitis B test', 'Blood', '', 'Non-reactive', 25000],
            ['Pregnancy test (HCG)', 'Urine', '', 'Negative', 8000],
        ];
    }

    public static function radiologyStudies(): array
    {
        return [
            ['Obstetric ultrasound', 'Ultrasound', 'Pelvis', 50000],
            ['Pelvic ultrasound', 'Ultrasound', 'Pelvis', 50000],
            ['Transvaginal ultrasound', 'Ultrasound', 'Pelvis', 70000],
        ];
    }

    public static function stockCategories(): array
    {
        return [['Maternity supplies', 'piece']];
    }

    public static function stockItems(): array
    {
        return [
            ['Oxytocin 10 IU', 'Injectables', 'ampoule', 100, 1500, 4000, 20],
            ['Misoprostol 200mcg', 'Tablets', 'tablet', 200, 500, 1500, 40],
            ['Magnesium sulphate 50%', 'Injectables', 'vial', 40, 3000, 8000, 10],
            ['Medroxyprogesterone 150mg (injectable contraceptive)', 'Injectables', 'vial', 100, 2500, 6000, 20],
            ['Ferrous sulphate + folic acid', 'Tablets', 'tablet', 1000, 70, 180, 200],
            ['Delivery kit', 'Maternity supplies', 'kit', 50, 15000, 35000, 10],
            ['Cord clamp', 'Maternity supplies', 'piece', 200, 300, 1000, 40],
        ];
    }

    public static function rooms(): array
    {
        return [
            ['Antenatal Room', 'consultation', 2],
            ['Labour Suite', 'theatre', 2],
        ];
    }

    public static function wards(): array
    {
        return [
            ['Maternity ward', 4, 40000],
            ['Labour ward', 2, 60000],
        ];
    }

    public static function visitReasons(): array
    {
        return ['Antenatal check-up', 'Postnatal check-up', 'Family planning', 'In labour'];
    }

    public static function complaints(): array
    {
        return [
            'Lower abdominal pain', 'Vaginal bleeding', 'Abnormal vaginal discharge',
            'Missed period', 'Labour pains', 'Reduced fetal movements',
        ];
    }

    public static function diagnoses(): array
    {
        return [
            'Normal pregnancy', 'Threatened abortion', 'Pre-eclampsia', 'Anaemia in pregnancy',
            'Pelvic inflammatory disease', 'Postpartum haemorrhage',
        ];
    }

    public static function remarks(): array
    {
        return ['Next antenatal visit in four weeks', 'Birth plan discussed'];
    }

    public static function orderTitles(): array
    {
        return [
            'procedure' => ['Antenatal examination', 'Vaginal examination', 'Delivery', 'Episiotomy repair', 'IUD insertion', 'Implant insertion'],
            'admission' => ['Admit — maternity', 'Admit to labour ward'],
        ];
    }
}
