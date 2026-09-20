<?php

namespace Database\Seeders;

/**
 * The cast of the demonstration hospital.
 *
 * Written out rather than generated, for two reasons. A faker roster reads
 * like a faker roster — "Bogisich, Dooley, Kshlerin" — and anybody being shown
 * the system spends the first minute noticing that instead of the software.
 * And a fixed list is reproducible: the same patient is always PT-…-000012, so
 * a screenshot, a bug report and a walkthrough script stay true next week.
 *
 * Ages are stored as an age in years rather than a date of birth, so the
 * roster does not quietly become a ward of pensioners as the years pass.
 *
 * @phpstan-type Person array{0:string,1:string,2:string,3:int,4:string}
 */
final class DemoPeople
{
    /**
     * first, last, sex, age, blood type.
     *
     * Deliberately spread across every age band the reports bucket by (0-17,
     * 18-39, 40-64, 65+) and every sex the system records, so no demographic
     * chart opens with an empty column.
     *
     * @return list<Person>
     */
    public static function roster(): array
    {
        return [
            // ── Children and adolescents ─────────────────────────────────
            ['Amina', 'Nabirye', 'female', 2, 'O+'],
            ['Joel', 'Ssempijja', 'male', 4, 'A+'],
            ['Grace', 'Atim', 'female', 6, 'B+'],
            ['Brian', 'Okumu', 'male', 8, 'O-'],
            ['Shakira', 'Namutebi', 'female', 9, 'AB+'],
            ['Ivan', 'Kyeyune', 'male', 11, 'A-'],
            ['Patience', 'Akello', 'female', 13, 'O+'],
            ['Denis', 'Wasswa', 'male', 14, 'B-'],
            ['Sandra', 'Nakiganda', 'female', 15, 'A+'],
            ['Emmanuel', 'Otim', 'male', 16, 'O+'],
            ['Faith', 'Auma', 'female', 17, 'B+'],

            // ── Young adults ─────────────────────────────────────────────
            ['Sarah', 'Nakato', 'female', 19, 'O+'],
            ['Ronald', 'Mugisha', 'male', 21, 'A+'],
            ['Winnie', 'Nabukenya', 'female', 22, 'AB-'],
            ['Isaac', 'Tumusiime', 'male', 23, 'O+'],
            ['Lydia', 'Achieng', 'female', 24, 'B+'],
            ['Moses', 'Kiprotich', 'male', 25, 'A+'],
            ['Justine', 'Nampijja', 'female', 26, 'O-'],
            ['Andrew', 'Byamugisha', 'male', 27, 'A+'],
            ['Rehema', 'Hassan', 'female', 28, 'B+'],
            ['Julius', 'Ochieng', 'male', 29, 'O+'],
            ['Betty', 'Nalubega', 'female', 30, 'AB+'],
            ['Kelvin', 'Mwesigwa', 'male', 31, 'A-'],
            ['Doreen', 'Ayebazibwe', 'female', 32, 'O+'],
            ['Samuel', 'Odongo', 'male', 33, 'B+'],
            ['Priscilla', 'Nansubuga', 'female', 34, 'O+'],
            ['Fredrick', 'Kizza', 'male', 35, 'A+'],
            ['Agnes', 'Kemigisha', 'female', 36, 'B-'],
            ['Timothy', 'Lubega', 'male', 37, 'O+'],
            ['Harriet', 'Nakawesi', 'female', 38, 'AB+'],
            ['Alex', 'Rwothomio', 'male', 39, 'A+'],

            // ── Middle age ───────────────────────────────────────────────
            ['Margaret', 'Nabwire', 'female', 41, 'O+'],
            ['Patrick', 'Ssentongo', 'male', 43, 'B+'],
            ['Jane', 'Chelimo', 'female', 44, 'A+'],
            ['Godfrey', 'Amanya', 'male', 46, 'O-'],
            ['Specioza', 'Namaganda', 'female', 47, 'B+'],
            ['Charles', 'Opio', 'male', 48, 'A+'],
            ['Robinah', 'Tibenda', 'female', 50, 'O+'],
            ['Wilson', 'Kaggwa', 'male', 52, 'AB+'],
            ['Esther', 'Nyakato', 'female', 53, 'A-'],
            ['Vincent', 'Muhumuza', 'male', 55, 'O+'],
            ['Beatrice', 'Adong', 'female', 57, 'B+'],
            ['Stephen', 'Katongole', 'male', 58, 'O+'],
            ['Josephine', 'Namusoke', 'female', 60, 'A+'],
            ['Paul', 'Ekwaro', 'male', 62, 'B-'],
            ['Christine', 'Kabahenda', 'female', 63, 'O+'],

            // ── Older patients ───────────────────────────────────────────
            ['Yusuf', 'Ssebagala', 'male', 66, 'A+'],
            ['Teopista', 'Nassuna', 'female', 68, 'O+'],
            ['Livingstone', 'Ojok', 'male', 71, 'B+'],
            ['Ruth', 'Kobusingye', 'female', 74, 'AB+'],
            ['Erasmus', 'Businge', 'male', 77, 'O-'],
            ['Perusi', 'Nakiwala', 'female', 81, 'A+'],
            ['Silvano', 'Aleper', 'male', 85, 'O+'],

            // ── A few from further afield, because a referral hospital has them
            ['Aisha', 'Mohamed', 'female', 34, 'B+'],
            ['Daniel', 'Mwangi', 'male', 42, 'A+'],
            ['Chipo', 'Moyo', 'female', 29, 'O+'],
            ['Tendai', 'Banda', 'other', 31, 'A+'],
            ['Elizabeth', 'Carter', 'female', 45, 'O+'],
        ];
    }

    /**
     * Known allergies, by roster position. Most people have none — a demo in
     * which everybody is allergic to something teaches the wrong reflex.
     *
     * @return array<int, list<string>>
     */
    public static function allergies(): array
    {
        return [
            3 => ['Penicillin'],
            9 => ['Sulfa drugs'],
            14 => ['Peanuts'],
            21 => ['Penicillin', 'Aspirin'],
            30 => ['Iodine contrast'],
            37 => ['Latex'],
            44 => ['Penicillin'],
            48 => ['Codeine'],
            52 => ['Sulfa drugs', 'Shellfish'],
        ];
    }

    /**
     * Long-term conditions, by roster position — concentrated in the older
     * half, as they are in life.
     *
     * @return array<int, list<string>>
     */
    public static function conditions(): array
    {
        return [
            10 => ['Asthma'],
            19 => ['Sickle cell trait'],
            25 => ['Hypertension'],
            31 => ['Type 2 diabetes'],
            34 => ['Hypertension'],
            38 => ['Hypertension', 'Type 2 diabetes'],
            40 => ['Chronic kidney disease (stage 2)'],
            43 => ['Epilepsy'],
            45 => ['Hypertension'],
            46 => ['Type 2 diabetes', 'Osteoarthritis'],
            47 => ['Hypertension', 'Heart failure'],
            48 => ['COPD'],
            49 => ['Osteoarthritis'],
            50 => ['Hypertension', 'Prostate enlargement'],
            51 => ['Type 2 diabetes'],
            52 => ['Dementia', 'Hypertension'],
        ];
    }
}
