<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\PatientSummaryService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/patients/{uuid}/summary - the patient page, as data (docs/api/patients.md).
 *
 * The same PatientSummaryService the web page reads, behind the same policy.
 * A section the caller may not see is OMITTED, never returned empty: an empty
 * money block would read as "owes nothing".
 */
class PatientSummaryController extends Controller
{
    public function __invoke(Request $request, Patient $patient, PatientSummaryService $service): JsonResponse
    {
        $this->authorize('view', $patient);
        app(\App\Services\Audit\RecordAccessLog::class)->opened($patient, $request->user(), $request);

        $sections = PatientSummaryService::sectionsFor($request->user());
        $summary = $service->summary($patient, $sections, (int) $request->query('limit', 5));
        // The app's Dental tab, by the same rule as the web's.
        if (\App\Services\DentalService::onPatient($patient, \App\Models\Hospital::find(app(\App\Support\CurrentHospital::class)->id()), $request->user())) {
            $sections[] = 'dental';
        }
        // Maternity and child health tabs, by the same rules as the web's
        // (docs/mother-child.md), each with its one-line state.
        $mc = $this->motherChild($patient, $request);
        $sections = array_merge($sections, array_keys($mc));

        foreach (['outstanding', 'billed_total', 'paid_total', 'card_balance'] as $key) {
            if (($summary['figures'][$key] ?? null) !== null) {
                $summary['figures'][$key.'_label'] = HospitalSettings::money($summary['figures'][$key]);
            }
        }

        $data = [
            'patient' => [
                'uuid' => (string) $patient->uuid,
                'patient_no' => (string) $patient->patient_no,
                'name' => $patient->full_name,
                'age' => $patient->age(),
                'sex' => $patient->sex?->value,
                'status' => $patient->status->value,
            ],
            'sections' => $sections,
            'figures' => $summary['figures'],
            'alerts' => $summary['alerts'],
        ] + $summary['recent'];

        if (in_array('money', $sections, true)) {
            $data['aging'] = $service->aging($patient)['buckets'];
        }

        return ApiResponse::success($data + $mc);
    }

    /**
     * The pregnancy, immunisation and growth sections this caller gets for
     * this patient, keyed by section, each with its short state.
     *
     * @return array<string, mixed>
     */
    private function motherChild(Patient $patient, Request $request): array
    {
        $hospital = \App\Support\MotherChild\MotherChild::hospital();
        $user = $request->user();
        $out = [];

        if (\App\Services\PregnancyService::onPatient($patient, $hospital, $user)) {
            $out['pregnancy'] = app(\App\Services\PregnancyService::class)->brief($patient);
        }
        if (\App\Services\ImmunisationService::onPatient($patient, $hospital, $user)) {
            $out['immunisation'] = \App\Services\ImmunisationService::counts(app(\App\Services\ImmunisationService::class)->card($patient));
        }
        if (\App\Services\GrowthService::onPatient($patient, $hospital, $user)) {
            $out['growth'] = app(\App\Services\GrowthService::class)->assessment($patient);
        }

        return $out;
    }
}
