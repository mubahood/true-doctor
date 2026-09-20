<?php

namespace App\Support;

/**
 * Curated starter data for the configuration catalogues. A fresh hospital can
 * import a sensible default set (and tweak prices) instead of typing every row
 * by hand. Prices are indicative defaults in the hospital's own currency and
 * are editable at import time. Nothing here is region-locked.
 */
class SampleCatalogue
{
    /**
     * Indicative UGX prices for a private clinic/hospital in East Africa,
     * grouped by specialty so a hospital sees the full range on offer — a flat
     * "general clinic" list undersells everything a real hospital charges for,
     * from a dental filling to a Caesarean section. `category` is additive: the
     * full Services page's importer (App\Livewire\Services\Index) only reads
     * name/price/tax_exempt and ignores it, so this stays backward compatible.
     *
     * @return list<array{name:string,price:float,tax_exempt:bool,category:string}>
     */
    public static function services(): array
    {
        $groups = [
            'General & outpatient' => [
                ['General consultation', 20000],
                ['Specialist consultation', 50000],
                ['Follow-up visit', 15000],
                ['Wound dressing', 15000],
                ['Injection administration', 5000],
                ['Nebulization', 20000],
                ['ECG', 40000],
                ['Physiotherapy session', 40000],
            ],
            'Maternity & gynaecology' => [
                ['Antenatal visit', 20000],
                ['Postnatal check-up', 15000],
                ['Normal delivery', 300000],
                ['Caesarean section', 1200000],
                ['Pap smear', 30000],
                ['Family planning counselling', 10000],
                ['IUD insertion', 60000],
                ['Gynaecological consultation', 40000],
            ],
            'Dental' => [
                ['Dental check-up', 20000],
                ['Tooth extraction', 40000],
                ['Root canal treatment', 250000],
                ['Dental filling', 60000],
                ['Scaling and polishing', 50000],
                ['Tooth whitening', 150000],
            ],
            'Surgery & minor procedures' => [
                ['Minor suturing', 40000],
                ['Incision and drainage of an abscess', 50000],
                ['Circumcision', 80000],
                ['Minor surgery (day case)', 200000],
                ['Hernia repair (day case)', 600000],
            ],
            'Paediatrics' => [
                ['Newborn check-up', 15000],
                ['Child immunisation', 10000],
                ['Growth monitoring', 10000],
            ],
            'Eye, ear, nose & throat' => [
                ['Eye examination', 20000],
                ['Ear syringing', 15000],
            ],
            'Emergency & inpatient' => [
                ['IV fluid administration', 20000],
                ['Blood transfusion', 80000],
                ['Ambulance service', 120000],
            ],
        ];

        $rows = [];
        foreach ($groups as $category => $items) {
            foreach ($items as [$name, $price]) {
                $rows[] = ['name' => $name, 'price' => (float) $price, 'tax_exempt' => false, 'category' => $category];
            }
        }

        return $rows;
    }

    /** @return list<array{name:string,specimen:string,unit:string,reference_range:string,price:float}> */
    /**
     * How a piece of work is usually written down, by kind of order.
     *
     * Only the kinds that take a free-text title are here. Lab and imaging
     * orders name catalogue rows instead — their tests and studies carry
     * results and reference ranges, so the picker has to be at placement —
     * and a phrase would compete with it rather than help.
     *
     * Pharmacy is NOT one of those: what was dispensed is an order ITEM, added
     * in the order's own dialog where it moves stock and bills in one
     * transaction (docs/orders.md). So placing one asks what it is, like any
     * other order, and these are the words for it.
     *
     * These are a FALLBACK. App\Livewire\Visits\Panels\Orders offers what this
     * hospital has actually written for that kind first, and tops up from here
     * — a hospital that says "Review BP" should be offered "Review BP", not a
     * phrase nobody there uses.
     *
     * @return array<string, list<string>> OrderType value => phrasings
     */
    public static function orderTitles(): array
    {
        return [
            'consultation' => [
                'Doctor review',
                'Specialist review',
                'Follow-up review',
                'Review results with the doctor',
                'Second opinion',
                'Discharge review',
            ],
            'procedure' => [
                'Wound dressing',
                'Suture removal',
                'Injection',
                'IV fluids',
                'Nebulisation',
                'Catheterisation',
                'Incision and drainage',
                'Plaster / splint',
            ],
            'pharmacy' => [
                'Dispense prescription',
                'Take-home medicines',
                'Ward stock for this patient',
                'Refill',
                'Single dose now',
                'Discharge medicines',
            ],
            'admission' => [
                'Admit for observation',
                'Admit — general ward',
                'Admit — maternity',
                'Admit — paediatric ward',
                'Admit for IV treatment',
                'Transfer to another ward',
            ],
        ];
    }

    /**
     * Why people actually come in, in the words a front desk uses.
     *
     * Not keyed by department: a reason is written before anyone knows where
     * the patient is going, and half of these fit any department. What the
     * hospital itself writes is blended in front of this (see the form).
     *
     * @return list<string>
     */
    public static function visitReasons(): array
    {
        return [
            'New complaint',
            'Follow-up visit',
            'Review test results',
            'Medication refill',
            'Antenatal check-up',
            'Immunisation',
            'Routine check-up',
            'Injury',
            'Referred from another facility',
            'Emergency',
        ];
    }

    /**
     * The words a clinician reaches for, by field.
     *
     * A typing aid, not a menu to pick from: each one lands in the box as
     * editable text, and what this hospital actually writes is offered ahead
     * of these (see App\Livewire\Visits\Panels\Clinical). The complaints and
     * diagnoses are the common presentations of East African primary care,
     * which is where this system is used.
     *
     * @return array<string, list<string>>
     */
    public static function clinicalPhrases(): array
    {
        return [
            'complaints' => [
                'Fever',
                'Headache',
                'Cough',
                'Abdominal pain',
                'Diarrhoea and vomiting',
                'Body weakness',
                'Chest pain',
                'Difficulty breathing',
            ],
            'diagnosis' => [
                'Malaria',
                'Upper respiratory tract infection',
                'Urinary tract infection',
                'Gastroenteritis',
                'Peptic ulcer disease',
                'Hypertension',
                'Type 2 diabetes',
                'Anaemia',
            ],
            'doctor_remarks' => [
                'Review in three days',
                'Review if no improvement',
                'Continue current medication',
                'Refer to a specialist',
                'Admit for observation',
                'Advised on fluids and rest',
                'Await laboratory results',
            ],
        ];
    }

    public static function labTests(): array
    {
        // Indicative UGX prices for a private laboratory in East Africa.
        return array_map(fn ($r) => ['name' => $r[0], 'specimen' => $r[1], 'unit' => $r[2], 'reference_range' => $r[3], 'price' => (float) $r[4]], [
            ['Complete blood count (CBC)', 'Blood', '', '', 25000],
            ['Malaria RDT', 'Blood', '', 'Negative', 8000],
            ['Random blood sugar', 'Blood', 'mmol/L', '3.9–7.8', 8000],
            ['Urinalysis', 'Urine', '', '', 15000],
            ['Widal test', 'Blood', '', '', 15000],
            ['HIV test', 'Blood', '', 'Non-reactive', 10000],
            ['Liver function test', 'Blood', '', '', 50000],
            ['Renal function test', 'Blood', '', '', 50000],
            ['Stool analysis', 'Stool', '', '', 12000],
            ['Pregnancy test (HCG)', 'Urine', '', 'Negative', 8000],
            ['Hepatitis B test', 'Blood', '', 'Non-reactive', 25000],
            ['Lipid profile', 'Blood', 'mmol/L', '', 50000],
            ['Blood grouping', 'Blood', '', '', 12000],
        ]);
    }

    /** @return list<array{name:string,modality:string,body_part:string,price:float}> */
    public static function radiologyStudies(): array
    {
        // Indicative UGX prices for a private imaging centre in East Africa.
        return array_map(fn ($r) => ['name' => $r[0], 'modality' => $r[1], 'body_part' => $r[2], 'price' => (float) $r[3]], [
            ['Chest X-ray', 'X-Ray', 'Chest', 40000],
            ['Abdominal ultrasound', 'Ultrasound', 'Abdomen', 50000],
            ['Obstetric ultrasound', 'Ultrasound', 'Pelvis', 50000],
            ['Pelvic X-ray', 'X-Ray', 'Pelvis', 45000],
            ['Skull X-ray', 'X-Ray', 'Head', 45000],
            ['CT scan — head', 'CT', 'Head', 300000],
            ['CT scan — abdomen', 'CT', 'Abdomen', 400000],
            ['MRI — brain', 'MRI', 'Head', 800000],
            ['Echocardiogram', 'Ultrasound', 'Heart', 120000],
            ['Mammogram', 'X-Ray', 'Breast', 100000],
        ]);
    }

    /** @return list<array{name:string,unit:string}> */
    public static function stockCategories(): array
    {
        return array_map(fn ($r) => ['name' => $r[0], 'unit' => $r[1]], [
            ['Tablets', 'tablet'],
            ['Capsules', 'capsule'],
            ['Syrups', 'bottle'],
            ['Injectables', 'vial'],
            ['Infusions', 'bag'],
            ['Consumables', 'piece'],
            ['Surgical supplies', 'piece'],
            ['Reagents', 'unit'],
        ]);
    }

    /**
     * A pharmacy worth opening with.
     *
     * Every other catalogue here could be imported from the start and the
     * drugs could not, so a new hospital's pharmacy was empty and nothing
     * could be dispensed until somebody typed one in by hand.
     *
     * Quantities are an opening count, and the prices are indicative retail
     * for East Africa in UGX. `category` names a row from stockCategories()
     * so the two import cleanly together.
     *
     * @return list<array{name:string,category:string,unit:string,quantity:float,cost_price:float,sale_price:float,reorder_level:float}>
     */
    public static function stockItems(): array
    {
        return array_map(fn ($r) => [
            'name' => $r[0], 'category' => $r[1], 'unit' => $r[2],
            'quantity' => (float) $r[3], 'cost_price' => (float) $r[4],
            'sale_price' => (float) $r[5], 'reorder_level' => (float) $r[6],
        ], [
            // name, category, unit, opening qty, cost, sale, reorder level
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
            ['Surgical blade No. 11', 'Surgical supplies', 'piece', 200, 400, 1000, 40],
            ['Malaria RDT kit', 'Reagents', 'kit', 300, 1800, 4000, 60],
        ]);
    }

    /** @return list<array{name:string,code:string}> */
    public static function departments(): array
    {
        return array_map(fn ($r) => ['name' => $r[0], 'code' => $r[1]], [
            ['Outpatient', 'OPD'],
            ['Inpatient', 'IPD'],
            ['Emergency', 'ER'],
            ['Pharmacy', 'PHM'],
            ['Laboratory', 'LAB'],
            ['Radiology', 'RAD'],
            ['Maternity', 'MAT'],
            ['Pediatrics', 'PED'],
            ['Surgery', 'SUR'],
            ['Dental', 'DEN'],
        ]);
    }

    /**
     * `beds` and `daily_charge` are starter defaults for the onboarding wizard,
     * which creates a ward's beds in the same step — indicative UGX/day rates
     * for a private hospital in East Africa, tiered the way most run in
     * practice (general cheapest, ICU and private priciest). The full Wards
     * page's importer (App\Livewire\Wards\Index) only reads `name` and ignores
     * the rest, so this stays backward compatible with it.
     *
     * @return list<array{name:string,beds:int,daily_charge:float}>
     */
    public static function wards(): array
    {
        return array_map(fn ($r) => ['name' => $r[0], 'beds' => $r[1], 'daily_charge' => (float) $r[2]], [
            ['General ward', 6, 30000],
            ['Maternity ward', 4, 40000],
            ['Pediatric ward', 4, 35000],
            ['Surgical ward', 4, 45000],
            ['Private ward', 2, 100000],
            ['Intensive care unit (ICU)', 2, 200000],
            ['Isolation ward', 2, 50000],
        ]);
    }

    /**
     * A small general-practice footprint — enough to start booking appointments
     * into named spaces on day one. `type` matches App\Enums\RoomType.
     *
     * @return list<array{name:string,type:string,capacity:int}>
     */
    public static function rooms(): array
    {
        return array_map(fn ($r) => ['name' => $r[0], 'type' => $r[1], 'capacity' => $r[2]], [
            ['Consultation Room 1', 'consultation', 1],
            ['Consultation Room 2', 'consultation', 1],
            ['Triage / Vitals Room', 'consultation', 2],
            ['Minor Procedure Room', 'theatre', 2],
        ]);
    }
}
