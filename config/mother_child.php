<?php

/*
|--------------------------------------------------------------------------
| Maternity and child health (roadmap 04 §4, docs/mother-child.md)
|--------------------------------------------------------------------------
|
| The country lists behind the pregnancy record, the immunisation register
| and the growth chart. Coded underneath, plain on screen: the keys are what
| is stored, the labels are what a nurse reads.
|
| VERIFY BEFORE RELEASE: schedules change. Each list names its source and the
| date it was last checked; check it against the current Ministry of Health
| (Uganda) card before a release that ships it.
|
*/

return [

    // ── Who the tabs are offered for ─────────────────────────────────────
    // A pregnancy tab on a 70-year-old man is a tab nobody opens. A woman in
    // these ages, or anyone who already has a pregnancy on record, sees it.
    // Wider than the WHO 15-49 band on purpose: adolescent pregnancies are
    // common here and must not be the ones the record hides.
    'childbearing_ages' => [12, 55],

    // Immunisation is offered under this age (years: the HPV dose is at ten),
    // growth under this one (the WHO standards stop at five); either one for
    // anyone with something already recorded.
    'immunisation_age_years' => 15,
    'growth_age_years' => 5,

    // ── Antenatal care ───────────────────────────────────────────────────
    'anc' => [
        'source' => 'WHO 2016 ANC model (8 contacts) as adopted in the Uganda Clinical Guidelines; checked 2026-10-17, verify against the current MoH ANC card.',

        // Contact number => the week of gestation it falls due. A contact
        // recorded at a gestation is counted against the first of these at or
        // after it (a booking at 18 weeks is contact 2; contact 1 was missed).
        'contacts' => [1 => 12, 2 => 20, 3 => 26, 4 => 30, 5 => 34, 6 => 36, 7 => 38, 8 => 40],

        // What each contact checks. `at` is the contact numbers (or 'all'). A check
        // not done stays due until done; one with `until` (a contact number) is
        // missed once that contact has passed.
        'checks' => [
            'bp' => ['label' => 'Blood pressure', 'at' => 'all'],
            'weight' => ['label' => 'Weight', 'at' => 'all'],
            'fundal_height' => ['label' => 'Fundal height', 'at' => [2, 3, 4, 5, 6, 7, 8]],
            'fetal_heart' => ['label' => 'Fetal heart', 'at' => [3, 4, 5, 6, 7, 8]],
            'urine' => ['label' => 'Urine protein', 'at' => [1, 3, 5, 7]],
            'hb' => ['label' => 'Haemoglobin', 'at' => [1, 4, 6]],
            'hiv' => ['label' => 'HIV test', 'at' => [1, 6]],
            'syphilis' => ['label' => 'Syphilis test', 'at' => [1]],
            'hepb' => ['label' => 'Hepatitis B test', 'at' => [1]],
            'blood_group' => ['label' => 'Blood group and rhesus', 'at' => [1]],
            'ultrasound' => ['label' => 'Ultrasound scan (before 24 weeks)', 'at' => [1], 'until' => 2],
            'td' => ['label' => 'Tetanus-diphtheria (Td)', 'at' => [1, 2]],
            'ifas' => ['label' => 'Iron and folic acid', 'at' => 'all'],
            'iptp' => ['label' => 'IPTp (SP) for malaria', 'at' => [2, 3, 4, 5, 6, 7, 8]],
            'deworming' => ['label' => 'Deworming (second trimester)', 'at' => [2], 'until' => 3],
            'llin' => ['label' => 'Mosquito net (LLIN)', 'at' => [1]],
        ],

        'risk_factors' => [
            'teen' => 'Under 18',
            'older' => 'Over 35',
            'grand_multipara' => 'Five or more births',
            'previous_caesarean' => 'Previous caesarean',
            'previous_loss' => 'Previous stillbirth or miscarriage',
            'previous_pph' => 'Previous bleeding after birth',
            'hypertension' => 'High blood pressure',
            'diabetes' => 'Diabetes',
            'hiv' => 'HIV positive',
            'anaemia' => 'Anaemia',
            'multiple' => 'Twins or more',
            'short_stature' => 'Height under 150 cm',
            'sickle_cell' => 'Sickle cell disease',
        ],
    ],

    // ── Immunisation (UNEPI) ─────────────────────────────────────────────
    'immunisation' => [
        'source' => 'UNEPI routine schedule, Ministry of Health Uganda; checked 2026-10-17, verify against the current child health card.',

        // A dose is "due" from its date for this many days, then "overdue".
        'grace_days' => 14,

        // A dose not given by this age is no longer chased (shown as missed),
        // unless the dose sets its own max_age_days.
        'default_max_age_days' => 1826,

        // key => the dose. `series` and `min_interval_days` hold a dose back
        // until the previous dose of the same series is that many days old.
        // The malaria vaccine (6, 7, 8 and 18 months) is given only in the
        // districts that offer it: a hospital there adds it here.
        'schedule' => [
            'bcg' => ['vaccine' => 'BCG', 'dose' => 'At birth', 'age_days' => 0],
            'opv0' => ['vaccine' => 'Polio (OPV)', 'dose' => 'OPV 0', 'age_days' => 0, 'max_age_days' => 14, 'series' => 'opv'],
            'opv1' => ['vaccine' => 'Polio (OPV)', 'dose' => 'OPV 1', 'age_days' => 42, 'series' => 'opv', 'min_interval_days' => 28],
            'penta1' => ['vaccine' => 'DPT-HepB-Hib', 'dose' => 'Dose 1', 'age_days' => 42, 'series' => 'penta'],
            'pcv1' => ['vaccine' => 'Pneumococcal (PCV)', 'dose' => 'Dose 1', 'age_days' => 42, 'series' => 'pcv'],
            'rota1' => ['vaccine' => 'Rotavirus', 'dose' => 'Dose 1', 'age_days' => 42, 'max_age_days' => 730, 'series' => 'rota'],
            'opv2' => ['vaccine' => 'Polio (OPV)', 'dose' => 'OPV 2', 'age_days' => 70, 'series' => 'opv', 'min_interval_days' => 28],
            'penta2' => ['vaccine' => 'DPT-HepB-Hib', 'dose' => 'Dose 2', 'age_days' => 70, 'series' => 'penta', 'min_interval_days' => 28],
            'pcv2' => ['vaccine' => 'Pneumococcal (PCV)', 'dose' => 'Dose 2', 'age_days' => 70, 'series' => 'pcv', 'min_interval_days' => 28],
            'rota2' => ['vaccine' => 'Rotavirus', 'dose' => 'Dose 2', 'age_days' => 70, 'max_age_days' => 730, 'series' => 'rota', 'min_interval_days' => 28],
            'opv3' => ['vaccine' => 'Polio (OPV)', 'dose' => 'OPV 3', 'age_days' => 98, 'series' => 'opv', 'min_interval_days' => 28],
            'penta3' => ['vaccine' => 'DPT-HepB-Hib', 'dose' => 'Dose 3', 'age_days' => 98, 'series' => 'penta', 'min_interval_days' => 28],
            'pcv3' => ['vaccine' => 'Pneumococcal (PCV)', 'dose' => 'Dose 3', 'age_days' => 98, 'series' => 'pcv', 'min_interval_days' => 28],
            'ipv1' => ['vaccine' => 'Polio (IPV)', 'dose' => 'IPV', 'age_days' => 98],
            'mr1' => ['vaccine' => 'Measles-Rubella', 'dose' => 'Dose 1', 'age_days' => 274, 'series' => 'mr'],
            'yf' => ['vaccine' => 'Yellow fever', 'dose' => 'Single dose', 'age_days' => 274],
            'mr2' => ['vaccine' => 'Measles-Rubella', 'dose' => 'Dose 2', 'age_days' => 548, 'series' => 'mr', 'min_interval_days' => 28],
            'hpv' => ['vaccine' => 'HPV', 'dose' => 'Single dose', 'age_days' => 3653, 'max_age_days' => 5478, 'sex' => 'female'],
        ],
    ],

    // ── Growth ───────────────────────────────────────────────────────────
    'growth' => [
        'source' => 'WHO Child Growth Standards 2006 (LMS tables, 0-60 months, from the WHO anthro package); MUAC cut-offs per WHO/UNICEF 2009.',
        // Mid-upper arm circumference (cm), children 6-59 months.
        'muac' => ['severe' => 11.5, 'moderate' => 12.5],
        // Faltering: no weight gained over at least this many days, or the
        // weight-for-age z-score fell by at least this much since the last
        // weighing (crossing a centile line).
        'faltering_days' => 28,
        'faltering_z_drop' => 0.67,
    ],
];
