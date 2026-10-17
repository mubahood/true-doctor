<?php

/*
 * The Ministry of Health forms (roadmap 04 §2): the age bands and the lines
 * each coded diagnosis is counted under. A code is matched by prefix, in the
 * order below, so the notifiable conditions come first and the broad groups
 * ("other injuries") last. A code that matches nothing is counted under "all
 * other diagnoses". When the Ministry revises a form, change this file and
 * the version string; nothing else.
 */

$c = fn (string $label, array $codes, bool $notifiable = false) => ['label' => $label, 'codes' => $codes, 'notifiable' => $notifiable];

return [

    'version' => 'HMIS 105/108 (2020), 033b (2020)',

    'age_bands' => [
        '0_28d' => ['label' => '0-28 days', 'to_days' => 28],
        '29d_4y' => ['label' => '29 days-4 years', 'to_years' => 4],
        '5_9y' => ['label' => '5-9 years', 'to_years' => 9],
        '10_19y' => ['label' => '10-19 years', 'to_years' => 19],
        '20y' => ['label' => '20 years and over', 'to_years' => null],
    ],

    'conditions' => [
        // Notifiable: also on the weekly 033b and the "report this" alert.
        'afp' => $c('Acute flaccid paralysis', ['A80', 'G61.0'], true),
        'animal_bites' => $c('Animal bites (suspected rabies)', ['W54', 'Z20.3', 'A82'], true),
        'cholera' => $c('Cholera', ['A00'], true),
        'dysentery' => $c('Dysentery', ['A03', 'A06.0'], true),
        'guinea_worm' => $c('Guinea worm', ['B72'], true),
        'malaria_confirmed' => $c('Malaria, confirmed', ['B50', 'B51', 'B52', 'B53'], true),
        'malaria_presumed' => $c('Malaria, presumed (not tested)', ['B54']),
        'measles' => $c('Measles', ['B05'], true),
        'meningitis' => $c('Bacterial meningitis', ['A39', 'G00', 'G01', 'G03'], true),
        'neonatal_tetanus' => $c('Neonatal tetanus', ['A33'], true),
        'plague' => $c('Plague', ['A20'], true),
        'yellow_fever' => $c('Yellow fever', ['A95'], true),
        'vhf' => $c('Viral haemorrhagic fevers', ['A96', 'A98', 'A99'], true),
        'aefi' => $c('Adverse events following immunisation', ['T88.0', 'T88.1'], true),
        'typhoid' => $c('Typhoid fever', ['A01'], true),
        'anthrax' => $c('Anthrax', ['A22'], true),
        'covid19' => $c('COVID-19', ['U07.1', 'U07.2'], true),

        'diarrhoea' => $c('Diarrhoea, acute', ['A09', 'A08', 'A04', 'A02']),
        'urethral_discharge' => $c('Urethral discharges', ['A54', 'A56']),
        'genital_ulcers' => $c('Genital ulcers', ['A51', 'A52', 'A53', 'A60', 'A57', 'A58']),
        'sti_other' => $c('Other sexually transmitted infections', ['A59', 'A63', 'A64']),
        'uti' => $c('Urinary tract infections', ['N10', 'N30', 'N39.0', 'O23']),
        'worms' => $c('Intestinal worms', ['B68', 'B76', 'B77', 'B78', 'B79', 'B80', 'B81', 'B82']),
        'schistosomiasis' => $c('Schistosomiasis', ['B65']),
        'leprosy' => $c('Leprosy', ['A30']),
        'tuberculosis' => $c('Tuberculosis', ['A15', 'A16', 'A17', 'A18', 'A19']),
        'pneumonia' => $c('Pneumonia', ['J12', 'J13', 'J14', 'J15', 'J16', 'J17', 'J18']),
        'cough_cold' => $c('Cough or cold (no pneumonia)', ['J00', 'J01', 'J02', 'J03', 'J04', 'J05', 'J06', 'J20', 'J21', 'R05']),
        'skin' => $c('Skin diseases', ['L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7', 'L8', 'L9', 'B35', 'B36', 'B86', 'B88']),
        'eye' => $c('Eye conditions', ['H0', 'H1', 'H2', 'H3', 'H4', 'H5']),
        'ear' => $c('Ear, nose and throat conditions', ['H6', 'H7', 'H8', 'H9']),
        'hepatitis_b' => $c('Hepatitis B', ['B16', 'B18.0', 'B18.1']),
        'brucellosis' => $c('Brucellosis', ['A23']),
        'sleeping_sickness' => $c('Sleeping sickness', ['B56']),
        'abortion' => $c('Abortion (all causes)', ['O02', 'O03', 'O04', 'O05', 'O06', 'O07']),
        'malaria_in_pregnancy' => $c('Malaria in pregnancy', ['O98.6']),
        'hypertension_in_pregnancy' => $c('High blood pressure in pregnancy', ['O10', 'O11', 'O13', 'O14', 'O15', 'O16']),
        'obstructed_labour' => $c('Obstructed labour', ['O64', 'O65', 'O66']),
        'puerperal_sepsis' => $c('Puerperal sepsis', ['O85', 'O86']),
        'haemorrhage_pregnancy' => $c('Haemorrhage in pregnancy', ['O20', 'O44', 'O45', 'O46', 'O67', 'O72']),
        'asthma' => $c('Asthma', ['J45', 'J46']),
        'oral' => $c('Oral diseases', ['K00', 'K01', 'K02', 'K03', 'K04', 'K05', 'K06', 'K07', 'K08', 'K09', 'K10', 'K11', 'K12', 'K13', 'K14']),
        'diabetes' => $c('Diabetes', ['E10', 'E11', 'E12', 'E13', 'E14']),
        'hypertension' => $c('Hypertension', ['I10', 'I11', 'I12', 'I13', 'I15']),
        'cardiovascular' => $c('Other cardiovascular diseases', ['I0', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'I9']),
        'depression' => $c('Depression', ['F32', 'F33']),
        'anxiety' => $c('Anxiety', ['F40', 'F41', 'F43']),
        'psychosis' => $c('Psychosis', ['F20', 'F21', 'F22', 'F23', 'F24', 'F25', 'F26', 'F27', 'F28', 'F29']),
        'bipolar' => $c('Bipolar disorder', ['F30', 'F31']),
        'epilepsy' => $c('Epilepsy', ['G40', 'G41']),
        'dementia' => $c('Dementia', ['F00', 'F01', 'F02', 'F03']),
        'substance' => $c('Alcohol and drug use', ['F10', 'F11', 'F12', 'F13', 'F14', 'F15', 'F16', 'F17', 'F18', 'F19']),
        'cancer' => $c('Cancers', ['C']),
        'sickle_cell' => $c('Sickle cell disease', ['D57']),
        'anaemia' => $c('Anaemia', ['D50', 'D51', 'D52', 'D53', 'D55', 'D56', 'D58', 'D59', 'D6', 'O99.0']),
        'malnutrition' => $c('Malnutrition', ['E40', 'E41', 'E42', 'E43', 'E44', 'E45', 'E46']),
        'injury_rta' => $c('Injuries, road traffic', ['V']),
        'injury_gbv' => $c('Injuries, gender-based violence', ['T74']),
        'snake_bites' => $c('Snake bites', ['T63.0']),
        'burns' => $c('Burns', ['T20', 'T21', 'T22', 'T23', 'T24', 'T25', 'T26', 'T27', 'T28', 'T29', 'T30', 'T31', 'T32']),
        'injury_other' => $c('Injuries, other', ['S', 'T0', 'T10', 'T11', 'T12', 'T13', 'T14', 'W', 'X', 'Y']),
    ],

];
