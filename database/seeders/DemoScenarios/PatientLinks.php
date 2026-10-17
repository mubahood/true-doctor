<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\AdmissionStatus;
use App\Enums\LabOrderStatus;
use App\Enums\RadiologyOrderStatus;
use App\Enums\SharedDocumentType;
use App\Models\Admission;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Models\RadiologyOrder;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PatientLinks\SharedDocuments;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Patient links in the demo hospital (roadmap 05 §4): a few documents already
 * sent to the patients they belong to, so the Patient links page and its open
 * log have something real to show.
 *
 * Uses what the demo already has: a visit's prescription, and (when the
 * three-month population is loaded) released lab results, reported studies
 * and discharges. Each link goes to that document's own patient. Nothing is
 * texted. Safe to run twice: a document that already has a link is skipped.
 */
final class PatientLinks
{
    public static function run(Hospital $hospital): void
    {
        $tenant = app(CurrentHospital::class);
        $before = $tenant->id();
        $tenant->set($hospital->id);

        try {
            $by = User::query()->where('hospital_id', $hospital->id)->where('role', 'doctor')->orderBy('id')->first()
                ?? User::query()->where('hospital_id', $hospital->id)->orderBy('id')->first();

            $candidates = [
                [SharedDocumentType::Prescription, Visit::query()->whereHas('prescriptions.doseItems')->latest('id')->limit(2)->get()],
                [SharedDocumentType::LabResult, LabOrder::query()->where('status', LabOrderStatus::Completed->value)->latest('id')->limit(2)->get()],
                [SharedDocumentType::RadiologyReport, RadiologyOrder::query()->where('status', RadiologyOrderStatus::Reported->value)->latest('id')->limit(1)->get()],
                [SharedDocumentType::DischargeSummary, Admission::query()->where('status', AdmissionStatus::Discharged->value)->latest('id')->limit(1)->get()],
            ];

            $made = 0;
            foreach ($candidates as [$type, $records]) {
                foreach ($records as $record) {
                    if (self::make($type, $record, $by, $made)) {
                        $made++;
                    }
                }
            }
        } finally {
            $tenant->set($before);
        }
    }

    /** One link, and what happened to it: opened, opened after a wrong code, or withdrawn. */
    private static function make(SharedDocumentType $type, Model $record, ?User $by, int $n): bool
    {
        $exists = PatientLink::query()->where('document_type', $type->value)->where('document_id', $record->getKey())->exists();
        if ($exists || app(SharedDocuments::class)->notReady($type, $record) !== null) {
            return false;
        }

        $service = app(PatientLinkService::class);
        $link = $service->issue($type, $record, $by)['link'];
        $phone = 'Mozilla/5.0 (Linux; Android 13; TECNO KI5) Mobile Safari/537.36';

        match ($n % 3) {
            0 => self::opens($service, $link, ['viewed', 'unlocked', 'downloaded'], $phone),
            1 => self::opens($service, $link, ['viewed', 'wrong_code', 'unlocked', 'downloaded'], $phone),
            default => $service->revoke($link, $by),
        };

        // Sent a day or two ago, not this second.
        $sent = Carbon::now()->subDays(1 + $n % 3)->setTime(10 + $n, 15);
        $link->forceFill(['created_at' => $sent, 'expires_at' => $sent->copy()->addDays(PatientLinkService::DAYS)])->save();

        return true;
    }

    /** @param  list<string>  $events */
    private static function opens(PatientLinkService $service, PatientLink $link, array $events, string $agent): void
    {
        foreach ($events as $event) {
            $service->record($link, $event, '102.85.14.7', $agent);
        }
        $wrong = count(array_filter($events, fn ($e) => $e === PatientLinkOpen::WRONG_CODE));
        $link->forceFill([
            'opens_count' => 1,
            'failed_attempts' => 0,
            'last_opened_at' => Carbon::now()->subHours(3 + $wrong),
        ])->save();
    }
}
