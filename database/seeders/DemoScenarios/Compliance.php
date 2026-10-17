<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\DataRequestKind;
use App\Enums\DataRequestStatus;
use App\Enums\MessagePurpose;
use App\Models\DataRequest;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\Compliance\DataRequestService;
use App\Services\Compliance\ProcessingAgreement;
use App\Services\Messaging\ConsentService;
use App\Support\CurrentHospital;
use App\Support\Edition;

/**
 * Data protection in the demo hospital (docs/compliance/README.md): the
 * processing agreement accepted by the administrator, and a short log of
 * patients' requests, one of each common kind: answered, refused and still open.
 *
 * Built through the services, from patients already on the register. A child's
 * request is made by their parent. Safe to run twice: a request is recognised
 * by its patient and kind.
 */
class Compliance
{
    public static function run(Hospital $hospital): void
    {
        app(CurrentHospital::class)->within($hospital->id, function () use ($hospital): void {
            $admin = User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')->orderBy('id')->first();
            if ($admin === null) {
                return;
            }

            if (Edition::isSaas()) {
                app(ProcessingAgreement::class)->accept($hospital, $admin);
            }

            // Grown-ups ask for themselves. A patient with no date of birth is
            // taken as an adult (the small base demo records none).
            $adults = Patient::query()->where(fn ($q) => $q->whereNull('dob')->orWhere('dob', '<=', now()->subYears(25)->toDateString()))
                ->orderBy('id')->limit(3)->get();
            $child = Patient::query()->whereNotNull('dob')->where('dob', '>', now()->subYears(12)->toDateString())->orderBy('id')->first();

            $plan = [
                // [patient, kind, days ago, details, close as, outcome]
                [$adults->get(0), DataRequestKind::Access, 40, 'Asked at reception for a copy of their record before seeing a specialist in Kampala.', DataRequestStatus::Done, 'Printed the personal data PDF and handed it over at reception after checking the national ID.'],
                [$adults->get(1), DataRequestKind::Correction, 6, 'The phone number on the record is an old one; asked that the new number be used for reminders.', null, null],
                [$adults->get(2), DataRequestKind::Objection, 20, 'Does not want appointment reminders by text.', DataRequestStatus::Done, 'Switched off text message consent on the record. Patient confirmed by phone.'],
                [$child, DataRequestKind::Deletion, 12, 'Parent asked for the child\'s record to be deleted after moving to another district.', DataRequestStatus::Refused, 'Medical records are kept for the retention period (until the child turns 25). Explained to the parent and offered a copy of the record instead.'],
            ];

            $service = app(DataRequestService::class);
            foreach ($plan as [$patient, $kind, $daysAgo, $details, $closeAs, $outcome]) {
                if (! $patient instanceof Patient) {
                    continue;
                }
                if (DataRequest::query()->where('patient_id', $patient->id)->where('kind', $kind->value)->exists()) {
                    continue;
                }
                $isChild = $patient->dob !== null && $patient->dob->gt(now()->subYears(18));
                $requester = $isChild ? (filled($patient->mother_name) ? $patient->mother_name : (filled($patient->father_name) ? $patient->father_name : 'Parent of '.$patient->full_name)) : $patient->full_name;

                $request = $service->log([
                    'patient_id' => $patient->id,
                    'requester_name' => $requester,
                    'requester_contact' => $patient->phone_1,
                    'kind' => $kind->value,
                    'received_on' => now()->subDays($daysAgo)->toDateString(),
                    'details' => $details,
                ], $admin);

                // What the outcome says was done, done: no more text messages to this patient.
                if ($kind === DataRequestKind::Objection) {
                    app(ConsentService::class)->record($patient, array_fill_keys(array_map(fn (MessagePurpose $p) => 'sms.'.$p->value, MessagePurpose::cases()), false), 'desk', $admin->id, 'Objected to text messages (data request)');
                }

                if ($closeAs !== null) {
                    $service->close($request, $closeAs, (string) $outcome, $admin);
                }
            }
        });
    }
}
