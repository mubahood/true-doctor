<?php

namespace Database\Seeders\DemoScenarios;

use App\Models\CaseSheetEntry;
use App\Models\DentalNote;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalNotesService;
use App\Services\VisitService;
use App\Support\CurrentHospital;

/**
 * Simple dental notes on two of the demo hospital's dental visits
 * (docs/dental.md, "Simple notes"): a presenting complaint, History and Oral
 * examination as plain text, and a blood pressure. The demo hospital keeps the
 * detailed case sheet as its choice (DentalCaseSheet), so these two show how
 * a visit written as simple notes reads, prints and carries its medical
 * history forward. Idempotent: a visit with notes or a case sheet is left alone.
 */
final class DentalNotes
{
    public static function run(Hospital $hospital): void
    {
        $context = app(CurrentHospital::class);
        $was = $context->id();
        $context->set($hospital->id);

        try {
            $department = Department::query()->where('name', 'like', '%dent%')->value('id');
            if ($department === null || DentalNote::query()->exists()) {
                return;
            }
            $visits = Visit::query()->with('patient')->where('department_id', $department)
                ->whereNotIn('id', CaseSheetEntry::query()->select('visit_id'))
                ->orderByDesc('id')->get()
                ->filter(fn (Visit $v) => $v->patient !== null && $v->patient->dob !== null && $v->patient->dob->age >= 18)
                ->take(2)->values();

            $stories = [
                [
                    'complaints' => "Pain in the upper right back tooth on cold drinks.\nTwo weeks, worse at night.",
                    'blood_pressure' => '128/84',
                    'notes' => [
                        'medical_history' => 'Hypertension, on amlodipine 5 mg daily. No known drug allergies.',
                        'family_history' => 'Mother diabetic.',
                        'dental_history' => 'Last dental visit three years ago, a filling on the lower left.',
                        'surgical_history' => 'None.',
                        'hard_tissues' => 'Deep caries 16 occlusal, tender to percussion. Old filling 36 intact.',
                        'soft_tissues' => 'Mild gingivitis around the upper molars.',
                        'xray' => 'Periapical 16: caries close to the pulp, no periapical lesion.',
                        'diagnosis' => 'Irreversible pulpitis 16',
                    ],
                ],
                [
                    'complaints' => 'Bleeding gums when brushing for some months.',
                    'blood_pressure' => '118/76',
                    'notes' => [
                        'medical_history' => 'Well. No medicines.',
                        'family_history' => 'Nothing of note.',
                        'dental_history' => 'Brushes once a day, never had a scale and polish.',
                        'hard_tissues' => 'Heavy calculus on the lower front teeth. No caries.',
                        'soft_tissues' => 'Red, swollen gums that bleed on probing.',
                        'diagnosis' => 'Chronic gingivitis',
                    ],
                ],
            ];

            foreach ($visits as $i => $visit) {
                $by = ($visit->doctor_user_id ? User::query()->find($visit->doctor_user_id) : null)
                    ?? User::query()->where('hospital_id', $hospital->id)->where('role', 'doctor')->orderBy('id')->first();
                if ($by === null) {
                    return;
                }
                $story = $stories[$i];
                if ($visit->blood_pressure === null) {
                    app(VisitService::class)->recordVitals($visit, ['blood_pressure' => $story['blood_pressure']]);
                }
                app(DentalNotesService::class)->save($visit, $story['notes'] + ['complaints' => $story['complaints']], $by);
            }
        } finally {
            $context->set($was);
        }
    }
}
