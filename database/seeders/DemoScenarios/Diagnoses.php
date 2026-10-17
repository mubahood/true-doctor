<?php

namespace Database\Seeders\DemoScenarios;

use App\Models\Hospital;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Services\DiagnosisService;

/**
 * Coded diagnoses on the demo hospital: most written diagnoses coded by the
 * doctor who saw the patient, the newest few left for the records officer's
 * worklist, so the Ministry reports have numbers and the worklist has work.
 */
final class Diagnoses
{
    public static function run(Hospital $hospital): void
    {
        $service = app(DiagnosisService::class);
        $visits = Visit::query()->whereNotNull('diagnosis')->where('diagnosis', '!=', '')
            ->whereNotIn('id', VisitDiagnosis::query()->select('visit_id'))
            ->orderBy('id')->get();
        $leave = $visits->sortByDesc('id')->take(4)->pluck('id')->all();

        foreach ($visits as $visit) {
            if (in_array($visit->id, $leave, true)) {
                continue;
            }
            $code = $service->suggest((string) $visit->diagnosis, $hospital->id)->first();
            if ($code === null) {
                continue;
            }
            $by = $visit->doctor_user_id !== null ? User::query()->find($visit->doctor_user_id) : null;
            $service->add($visit, $code->code, true, 'confirmed', null, $by);
        }
    }
}
