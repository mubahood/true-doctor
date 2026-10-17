<?php

namespace App\Support\CaseSheet;

/**
 * The dental case sheet: its sections, the words a dentist picks from, the
 * short diagnosis list with its ICD-10 codes, the instructions handed to a
 * patient and the review each procedure suggests.
 *
 * The headings are the ones taught in dental school and used on paper:
 * history (complaint, dental, medical, surgical, financial), examination
 * (extra-oral, intra-oral soft tissues, hard tissues, investigations),
 * diagnosis, treatment done, plan. A section is a list of fields; the same
 * definition draws the web form, checks what the app sends, and prints.
 *
 * Field types: `chips` (several of the options), `choice` (one of them),
 * `text` (one line, with `suggest` offered as a list), `textarea`, and `list`
 * (comma separated, kept on the patient: allergies, conditions). A section
 * with `normal` has a one-tap "Normal": the fields marked `abnormal` are only
 * written when something is not normal.
 */
final class DentalCaseSheet
{
    public const TEMPLATE = 'dental';

    /** Where each section prints and shows, in the order a dentist works. */
    public const GROUPS = [
        'history' => 'History',
        'examination' => 'Examination',
        'diagnosis' => 'Diagnosis',
        'treatment' => 'Treatment done today',
        'plan' => 'Plan and follow-up',
    ];

    /**
     * The sections of the visit. `scope` visit: written per visit; patient:
     * belongs to the patient and is confirmed each visit.
     *
     * @return array<string, array{label:string, group:string, scope:string, normal?:bool, fields:array<string,array<string,mixed>>}>
     */
    public static function sections(): array
    {
        return [
            'complaint' => [
                'label' => 'Presenting complaint',
                'group' => 'history',
                'scope' => 'visit',
                'fields' => [
                    'picks' => ['type' => 'chips', 'label' => 'Complaint', 'options' => [
                        'toothache' => 'Toothache', 'swelling' => 'Swelling', 'bleeding_gums' => 'Bleeding gums',
                        'broken_tooth' => 'Broken tooth', 'loose_tooth' => 'Loose tooth', 'sensitivity' => 'Sensitivity',
                        'bad_breath' => 'Bad breath', 'checkup' => 'Check-up', 'cleaning' => 'Cleaning',
                        'ortho_review' => 'Braces review', 'denture' => 'Denture problem',
                    ]],
                    'where' => ['type' => 'text', 'label' => 'Where', 'placeholder' => 'Tooth or area, for example lower right back tooth'],
                    'duration' => ['type' => 'text', 'label' => 'How long', 'suggest' => ['Since today', '2 days', '5 days', '1 week', '2 weeks', '1 month', 'Several months']],
                    'worse' => ['type' => 'chips', 'label' => 'Worse with', 'options' => [
                        'cold' => 'Cold', 'hot' => 'Hot', 'sweet' => 'Sweet', 'biting' => 'Biting', 'night' => 'At night', 'lying' => 'Lying down',
                    ]],
                    'notes' => ['type' => 'textarea', 'label' => 'In the patient\'s words'],
                ],
            ],
            'dental_history' => [
                'label' => 'Dental history',
                'group' => 'history',
                'scope' => 'visit',
                'fields' => [
                    'last_visit' => ['type' => 'choice', 'label' => 'Last dental visit', 'options' => [
                        'never' => 'Never', 'under_6m' => 'Under 6 months', '6_12m' => '6 to 12 months', '1_2y' => '1 to 2 years', 'over_2y' => 'Over 2 years',
                    ]],
                    'previous' => ['type' => 'chips', 'label' => 'Previous treatment', 'options' => [
                        'fillings' => 'Fillings', 'extractions' => 'Extractions', 'root_canal' => 'Root canal', 'scaling' => 'Scaling',
                        'crowns' => 'Crowns or bridges', 'braces' => 'Braces', 'dentures' => 'Dentures',
                    ]],
                    'brushing' => ['type' => 'choice', 'label' => 'Brushing', 'options' => [
                        'twice' => 'Twice a day', 'once' => 'Once a day', 'irregular' => 'Not every day',
                    ]],
                    'aids' => ['type' => 'chips', 'label' => 'Also uses', 'options' => [
                        'floss' => 'Floss', 'mouthwash' => 'Mouthwash', 'toothpick' => 'Toothpick', 'chewing_stick' => 'Chewing stick',
                    ]],
                    'habits' => ['type' => 'chips', 'label' => 'Habits', 'options' => [
                        'tobacco' => 'Tobacco', 'alcohol' => 'Alcohol', 'sugary_drinks' => 'Sugary drinks', 'bruxism' => 'Grinds teeth',
                    ]],
                    'notes' => ['type' => 'textarea', 'label' => 'Notes'],
                ],
            ],
            'medical' => [
                'label' => 'Medical history',
                'group' => 'history',
                'scope' => 'patient',
                'fields' => [
                    'allergies' => ['type' => 'list', 'label' => 'Allergies', 'attribute' => 'allergies', 'hint' => 'Separate with commas. Write "None" when the patient has none.'],
                    'conditions' => ['type' => 'list', 'label' => 'Long-term conditions', 'attribute' => 'chronic_conditions'],
                    'red_flags' => ['type' => 'chips', 'label' => 'Before treatment, check', 'options' => \App\Support\Clinical\MedicalFlags::OPTIONS],
                    'medicines' => ['type' => 'textarea', 'label' => 'Current medicines'],
                    'pregnancy' => ['type' => 'choice', 'label' => 'Pregnancy', 'women' => true, 'options' => [
                        'no' => 'Not pregnant', 'pregnant' => 'Pregnant', 'breastfeeding' => 'Breastfeeding',
                    ]],
                    'notes' => ['type' => 'text', 'label' => 'Other'],
                ],
            ],
            'surgical' => [
                'label' => 'Surgical history',
                'group' => 'history',
                'scope' => 'patient',
                'fields' => [
                    'operations' => ['type' => 'textarea', 'label' => 'Past operations'],
                    'extractions' => ['type' => 'choice', 'label' => 'Past extractions', 'options' => ['none' => 'None', 'yes' => 'Yes']],
                    'bleeding' => ['type' => 'choice', 'label' => 'Problem with bleeding or healing', 'options' => ['no' => 'No', 'yes' => 'Yes']],
                    'details' => ['type' => 'text', 'label' => 'Details'],
                ],
            ],
            'financial' => [
                'label' => 'Financial history',
                'group' => 'history',
                'scope' => 'patient',
                'fields' => [
                    'arrangement' => ['type' => 'text', 'label' => 'Payment arrangement', 'placeholder' => 'For example: pays in three instalments, employer pays half'],
                ],
            ],
            'extra_oral' => [
                'label' => 'Extra-oral',
                'group' => 'examination',
                'scope' => 'visit',
                'normal' => true,
                'fields' => [
                    'face' => ['type' => 'text', 'label' => 'Face symmetry', 'abnormal' => true],
                    'swelling' => ['type' => 'text', 'label' => 'Swelling', 'abnormal' => true],
                    'lymph_nodes' => ['type' => 'text', 'label' => 'Lymph nodes', 'abnormal' => true],
                    'tmj' => ['type' => 'text', 'label' => 'Jaw joint (TMJ)', 'abnormal' => true],
                    'mouth_opening' => ['type' => 'text', 'label' => 'Mouth opening', 'abnormal' => true],
                ],
            ],
            'intra_oral' => [
                'label' => 'Intra-oral soft tissues',
                'group' => 'examination',
                'scope' => 'visit',
                'normal' => true,
                'fields' => [
                    'gingiva' => ['type' => 'text', 'label' => 'Gums', 'abnormal' => true],
                    'mucosa' => ['type' => 'text', 'label' => 'Mucosa', 'abnormal' => true],
                    'tongue' => ['type' => 'text', 'label' => 'Tongue', 'abnormal' => true],
                    'floor' => ['type' => 'text', 'label' => 'Floor of mouth', 'abnormal' => true],
                    'palate' => ['type' => 'text', 'label' => 'Palate', 'abnormal' => true],
                    'hygiene' => ['type' => 'choice', 'label' => 'Oral hygiene', 'options' => ['good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor']],
                    'calculus' => ['type' => 'choice', 'label' => 'Calculus', 'options' => ['none' => 'None', 'mild' => 'Mild', 'moderate' => 'Moderate', 'heavy' => 'Heavy']],
                    'plaque' => ['type' => 'choice', 'label' => 'Plaque', 'options' => ['none' => 'None', 'mild' => 'Mild', 'moderate' => 'Moderate', 'heavy' => 'Heavy']],
                    'pockets' => ['type' => 'text', 'label' => 'Periodontal pockets', 'placeholder' => 'If measured, for example 36 5 mm'],
                ],
            ],
            'treatment_notes' => [
                'label' => 'Anaesthetic and materials',
                'group' => 'treatment',
                'scope' => 'visit',
                'fields' => [
                    'anaesthetic' => ['type' => 'text', 'label' => 'Anaesthetic', 'suggest' => ['Lidocaine 2% with adrenaline, 1 cartridge', 'Lidocaine 2% with adrenaline, 2 cartridges', 'Articaine 4%, 1 cartridge', 'Topical only', 'None']],
                    'materials' => ['type' => 'text', 'label' => 'Materials', 'suggest' => ['Composite', 'Glass ionomer', 'Amalgam', 'Calcium hydroxide liner', 'Gutta-percha']],
                    'notes' => ['type' => 'textarea', 'label' => 'Notes'],
                ],
            ],
            'instructions' => [
                'label' => 'Instructions to the patient',
                'group' => 'plan',
                'scope' => 'visit',
                'fields' => [
                    'templates' => ['type' => 'chips', 'label' => 'Given', 'options' => array_map(fn (array $i) => $i['label'], self::INSTRUCTIONS)],
                    'extra' => ['type' => 'textarea', 'label' => 'Other instructions'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function section(string $key): ?array
    {
        return self::sections()[$key] ?? null;
    }

    /** @return list<string> the sections of one scope, in order */
    public static function keys(string $scope): array
    {
        return array_keys(array_filter(self::sections(), fn (array $s) => $s['scope'] === $scope));
    }

    /**
     * What a patient is told after a procedure, printable and sendable.
     *
     * @var array<string, array{label:string, lines:list<string>}>
     */
    public const INSTRUCTIONS = [
        'after_extraction' => ['label' => 'After an extraction', 'lines' => [
            'Bite firmly on the gauze for 30 minutes, then remove it gently.',
            'Do not rinse, spit or drink through a straw today: the clot that heals the socket must stay in place.',
            'From tomorrow, rinse gently with warm salt water (half a teaspoon in a cup) after meals, for one week.',
            'Eat soft, cool food on the other side today. No hot drinks for 24 hours.',
            'Do not smoke or drink alcohol for at least 48 hours.',
            'Some oozing and swelling is normal. If bleeding does not stop, bite on a clean gauze or a damp tea bag for 30 minutes and call the clinic.',
            'Take the medicines exactly as prescribed.',
        ]],
        'after_root_canal' => ['label' => 'After a root canal', 'lines' => [
            'The tooth may be tender for a few days. Take the pain medicine as prescribed.',
            'Do not chew hard food on this tooth until it has its final filling or crown.',
            'If a temporary filling falls out, call the clinic.',
            'Come back on the date given to finish the treatment. A tooth left half treated can break or get infected again.',
        ]],
        'after_scaling' => ['label' => 'After scaling and polishing', 'lines' => [
            'Your gums may bleed a little and your teeth may feel sensitive for a few days. This settles.',
            'Brush twice a day with a soft brush and fluoride toothpaste, including along the gum line.',
            'Clean between your teeth once a day.',
            'Cut down on sugary drinks and snacks between meals.',
        ]],
        'after_filling' => ['label' => 'After a filling', 'lines' => [
            'Do not eat until the numbness has worn off, so you do not bite your cheek or tongue.',
            'The tooth may be sensitive to cold for a few days.',
            'If your bite feels high or the pain gets worse, call the clinic.',
        ]],
        'new_denture' => ['label' => 'A new denture', 'lines' => [
            'Wear the denture as much as you can in the first days, so your mouth gets used to it.',
            'Start with soft food cut into small pieces, chewing on both sides.',
            'Take the denture out at night and keep it in clean water.',
            'Clean it every day with a soft brush and soap, over a basin of water so it does not break if dropped.',
            'Sore spots are common at first. Come back for an adjustment; do not file the denture yourself.',
        ]],
    ];

    /**
     * The short diagnosis list: what a dentist writes, its ICD-10 code (the
     * Ministry's oral diseases line counts the K codes), and what it marks on
     * the tooth. `chart` is a DentalFinding value or null; `tooth` says
     * whether the diagnosis is of a tooth (true), of the mouth (false), or
     * either (null).
     *
     * @var array<string, array{label:string, code:string, chart:?string, tooth:?bool}>
     */
    public const DIAGNOSES = [
        'caries_enamel' => ['label' => 'Caries, enamel', 'code' => 'K02.0', 'chart' => 'caries', 'tooth' => true],
        'caries_dentine' => ['label' => 'Caries, dentine', 'code' => 'K02.1', 'chart' => 'caries', 'tooth' => true],
        'caries_root' => ['label' => 'Root caries', 'code' => 'K02.2', 'chart' => 'caries', 'tooth' => true],
        'pulpitis_reversible' => ['label' => 'Reversible pulpitis', 'code' => 'K04.0', 'chart' => null, 'tooth' => true],
        'pulpitis_irreversible' => ['label' => 'Irreversible pulpitis', 'code' => 'K04.0', 'chart' => null, 'tooth' => true],
        'pulp_necrosis' => ['label' => 'Pulp necrosis', 'code' => 'K04.1', 'chart' => null, 'tooth' => true],
        'apical_periodontitis' => ['label' => 'Apical periodontitis', 'code' => 'K04.5', 'chart' => null, 'tooth' => true],
        'abscess' => ['label' => 'Periapical abscess', 'code' => 'K04.7', 'chart' => null, 'tooth' => true],
        'abscess_sinus' => ['label' => 'Periapical abscess with sinus', 'code' => 'K04.6', 'chart' => null, 'tooth' => true],
        'retained_root' => ['label' => 'Retained root', 'code' => 'K08.3', 'chart' => 'to_extract', 'tooth' => true],
        'fracture' => ['label' => 'Fractured tooth', 'code' => 'S02.5', 'chart' => 'fracture', 'tooth' => true],
        'impacted' => ['label' => 'Impacted tooth', 'code' => 'K01.1', 'chart' => null, 'tooth' => true],
        'gingivitis' => ['label' => 'Gingivitis', 'code' => 'K05.1', 'chart' => null, 'tooth' => false],
        'periodontitis' => ['label' => 'Periodontitis', 'code' => 'K05.3', 'chart' => null, 'tooth' => null],
        'malocclusion' => ['label' => 'Malocclusion', 'code' => 'K07.4', 'chart' => null, 'tooth' => false],
        'missing_teeth' => ['label' => 'Missing teeth', 'code' => 'K08.1', 'chart' => null, 'tooth' => null],
    ];

    /** X-rays taken at the chair. @var array<string,string> */
    public const XRAY_KINDS = [
        'periapical' => 'Periapical',
        'bitewing' => 'Bitewing',
        'opg' => 'Panoramic (OPG)',
        'occlusal' => 'Occlusal',
    ];

    /**
     * The review a procedure suggests, in days, matched on the words of its
     * name on the price list. The first match wins; nothing matched, no review.
     *
     * @var list<array{0:string, 1:int, 2:string}> words, days, why
     */
    public const REVIEWS = [
        ['extract', 7, 'Review the socket'],
        ['root canal', 7, 'Continue the root canal'],
        ['incision', 2, 'Review the abscess'],
        ['denture', 2, 'Adjust the denture'],
        ['temporary', 14, 'Replace the temporary filling'],
        ['braces', 28, 'Braces adjustment'],
        ['scaling', 180, 'Check-up and cleaning'],
        ['filling', 180, 'Check-up'],
    ];

    /** Which instructions fit a procedure, by the words of its name. @var array<string,string> words => instruction key */
    public const INSTRUCTIONS_FOR = [
        'extract' => 'after_extraction',
        'root canal' => 'after_root_canal',
        'scaling' => 'after_scaling',
        'cleaning' => 'after_scaling',
        'filling' => 'after_filling',
        'denture' => 'new_denture',
    ];

    /** @return array{days:int, why:string}|null */
    public static function reviewFor(string $procedure): ?array
    {
        $name = mb_strtolower($procedure);
        foreach (self::REVIEWS as [$words, $days, $why]) {
            if (str_contains($name, $words)) {
                return ['days' => $days, 'why' => $why];
            }
        }

        return null;
    }

    public static function instructionFor(string $procedure): ?string
    {
        $name = mb_strtolower($procedure);
        foreach (self::INSTRUCTIONS_FOR as $words => $key) {
            if (str_contains($name, $words)) {
                return $key;
            }
        }

        return null;
    }
}
