<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\PatientSex;
use App\Models\CaseSheetEntry;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\CaseSheetService;
use App\Services\DentalCaseSheetService;
use App\Support\CurrentHospital;

/**
 * Full dental case sheets on the demo hospital's dental visits (docs/dental.md,
 * "The case sheet"): history, examination, a diagnosis per tooth that marks
 * the chart, an X-ray at the chair on an open visit, the anaesthetic used and
 * the instructions given.
 *
 * Only on adults seen in the dental department (these are permanent teeth),
 * three stories in turn: the abscess on the lower right first molar the visit
 * itself describes, bleeding gums needing a scale and polish, and a front
 * tooth broken in a fall. The pregnancy question is asked only where it fits
 * (the service drops it otherwise). Idempotent: a visit with a case sheet is
 * left as it is.
 */
final class DentalCaseSheet
{
    public static function run(Hospital $hospital): void
    {
        $context = app(CurrentHospital::class);
        $was = $context->id();
        $context->set($hospital->id);

        try {
            $department = Department::query()->where('name', 'like', '%dent%')->value('id');
            if ($department === null) {
                return;
            }
            $visits = Visit::query()->with('patient')->where('department_id', $department)
                ->whereNotIn('id', CaseSheetEntry::query()->select('visit_id'))
                // An open visit first: an X-ray is recorded at the chair, on an open visit.
                ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")->orderByDesc('id')->get()
                ->filter(fn (Visit $v) => $v->patient !== null && $v->patient->dob !== null && $v->patient->dob->age >= 18)
                ->take(3)->values();

            $xray = Service::query()->firstOrCreate(['name' => 'Dental X-ray (periapical)'], [
                'price' => '20000.00', 'category' => 'Dental', 'tax_exempt' => false, 'is_active' => true,
            ]);

            foreach ($visits as $i => $visit) {
                $by = ($visit->doctor_user_id ? User::query()->find($visit->doctor_user_id) : null)
                    ?? User::query()->where('hospital_id', $hospital->id)->where('role', 'doctor')->orderBy('id')->first();
                if ($by === null) {
                    return;
                }
                self::story($i % 3, $visit, $by, $xray);
            }
        } finally {
            $context->set($was);
        }
    }

    private static function story(int $n, Visit $visit, User $by, Service $xray): void
    {
        $sections = app(CaseSheetService::class);
        $sheets = app(DentalCaseSheetService::class);
        $patient = $visit->patient;
        $woman = $patient->sex === PatientSex::Female;

        $stories = [
            [
                'complaint' => ['picks' => ['toothache', 'swelling'], 'where' => 'Lower right back tooth', 'duration' => '5 days', 'worse' => ['biting', 'night'], 'notes' => 'Could not sleep for two nights. Face swelling since yesterday.'],
                'dental_history' => ['last_visit' => 'over_2y', 'previous' => ['extractions'], 'brushing' => 'once', 'habits' => ['sugary_drinks']],
                'medical' => ['allergies' => 'None', 'red_flags' => ['hypertension'], 'medicines' => 'Amlodipine 5 mg once a day', 'pregnancy' => 'no'],
                'surgical' => ['extractions' => 'yes', 'bleeding' => 'no', 'details' => 'Lower left molar removed in 2019, healed well.'],
                'extra_oral' => ['swelling' => 'Right submandibular, soft, tender', 'lymph_nodes' => 'Right submandibular node palpable'],
                'intra_oral' => ['gingiva' => 'Swollen around 46', 'hygiene' => 'fair', 'calculus' => 'mild', 'plaque' => 'moderate'],
                'diagnoses' => [['abscess', 46, null], ['caries_dentine', 47, 'O']],
                'xray' => ['periapical', [46, 47], 'Periapical radiolucency at the roots of 46. Caries into dentine on 47 O, clear of the pulp.'],
                'treatment_notes' => ['anaesthetic' => 'Lidocaine 2% with adrenaline, 2 cartridges', 'notes' => '46 extracted, socket irrigated, gauze pack.'],
                'instructions' => ['templates' => ['after_extraction']],
            ],
            [
                'complaint' => ['picks' => ['bleeding_gums', 'cleaning'], 'duration' => 'Several months', 'notes' => 'Gums bleed when brushing.'],
                'dental_history' => ['last_visit' => '1_2y', 'previous' => ['fillings', 'scaling'], 'brushing' => 'twice', 'aids' => ['toothpick'], 'habits' => ['tobacco']],
                'medical' => ['allergies' => 'Penicillin', 'red_flags' => ['diabetes'], 'medicines' => 'Metformin 500 mg twice a day', 'pregnancy' => 'no'],
                'surgical' => ['extractions' => 'none', 'bleeding' => 'no'],
                'extra_oral' => ['normal' => true],
                'intra_oral' => ['gingiva' => 'Red, bleeding on probing, upper and lower anteriors', 'hygiene' => 'poor', 'calculus' => 'heavy', 'plaque' => 'heavy', 'pockets' => '4 mm at 31 and 41'],
                'diagnoses' => [['gingivitis', null, null], ['periodontitis', null, null]],
                'xray' => null,
                'treatment_notes' => ['anaesthetic' => 'Topical only', 'notes' => 'Full mouth scale and polish. Oral hygiene shown with a soft brush.'],
                'instructions' => ['templates' => ['after_scaling'], 'extra' => 'Stop smoking: it slows the healing of your gums.'],
            ],
            [
                'complaint' => ['picks' => ['broken_tooth', 'sensitivity'], 'where' => 'Upper front tooth', 'duration' => '2 days', 'worse' => ['cold'], 'notes' => 'Fell off a boda boda.'],
                'dental_history' => ['last_visit' => 'never', 'brushing' => 'twice'],
                'medical' => ['allergies' => 'None', 'red_flags' => [], 'pregnancy' => 'no'],
                'surgical' => ['operations' => 'Appendix removed in 2015', 'extractions' => 'none', 'bleeding' => 'no'],
                'extra_oral' => ['face' => 'Healing graze on the upper lip'],
                'intra_oral' => ['normal' => true, 'hygiene' => 'good', 'calculus' => 'none', 'plaque' => 'mild'],
                'diagnoses' => [['fracture', 11, 'I'], ['pulpitis_reversible', 11, null]],
                'xray' => ['periapical', [11, 21], 'No root fracture. Pulp clear of the fracture line on 11.'],
                'treatment_notes' => ['materials' => 'Composite', 'notes' => 'Incisal edge of 11 built up in composite.'],
                'instructions' => ['templates' => ['after_filling']],
            ],
        ];
        $s = $stories[$n];
        if (! $woman) {
            unset($s['medical']['pregnancy']);
        }

        foreach (['complaint', 'dental_history', 'extra_oral', 'intra_oral', 'treatment_notes', 'instructions'] as $key) {
            $sections->save($visit, $key, $s[$key], $by);
        }
        foreach (['medical', 'surgical'] as $key) {
            $sections->savePatientHistory($patient, $key, $s[$key], $visit, $by);
        }
        foreach ($s['diagnoses'] as [$condition, $tooth, $surfaces]) {
            try {
                $sheets->addDiagnosis($visit, $condition, $tooth, $surfaces, $by);
            } catch (\Illuminate\Validation\ValidationException) {
                // already on the visit
            }
        }
        if ($s['xray'] !== null && $visit->isOpen()) {
            [$kind, $teeth, $finding] = $s['xray'];
            $sheets->recordXray($visit, $kind, $teeth, $finding, $xray, null, $by);
        }
    }
}
