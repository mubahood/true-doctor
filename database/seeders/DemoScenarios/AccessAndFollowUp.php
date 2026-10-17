<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RoleAbility;
use App\Models\User;
use App\Models\UserAbility;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Staff and access, and follow-up that runs itself (docs/rbac.md,
 * docs/follow-up.md), on the demo hospital:
 *
 *  - the clinic lets its pharmacists change prices (a role choice), and gives
 *    one nurse the extra ability to take payments;
 *  - reviews booked from past visits: one later this week, one the patient
 *    did not come to, one left booked with the day gone;
 *  - dental check-ups falling due: one this week, one a few weeks overdue,
 *    one three months overdue, for patients old enough to have been seen.
 *
 * Safe to run again: each part looks for its own rows first.
 */
final class AccessAndFollowUp
{
    public static function run(Hospital $hospital): void
    {
        self::access($hospital);
        self::reviews($hospital);
        self::recalls($hospital);
    }

    private static function access(Hospital $hospital): void
    {
        $admin = User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')->orderBy('id')->first();

        $choice = RoleAbility::query()->firstOrNew(['role' => 'pharmacist', 'ability' => 'change_prices']);
        if (! $choice->exists) {
            $choice->forceFill(['hospital_id' => $hospital->id, 'allowed' => true, 'changed_by' => $admin?->id])->save();
        }

        $nurse = User::query()->where('hospital_id', $hospital->id)->where('role', 'nurse')->orderBy('id')->first();
        if ($nurse !== null) {
            $extra = UserAbility::query()->firstOrNew(['user_id' => $nurse->id, 'ability' => 'take_payments']);
            if (! $extra->exists) {
                $extra->forceFill(['hospital_id' => $hospital->id, 'granted_by' => $admin?->id])->save();
            }
        }

        app(\App\Support\Access\PermissionResolver::class)->flush();
    }

    private static function reviews(Hospital $hospital): void
    {
        if (Appointment::query()->whereNotNull('origin_visit_id')->where('reason', 'Review')->exists()) {
            return;
        }

        // Visits with a doctor, a few weeks back, whose patients have not been since.
        $visits = Visit::query()->whereNotNull('doctor_user_id')
            ->where('created_at', '<', Carbon::now()->subDays(10))
            ->orderByDesc('created_at')->limit(30)->get()
            ->unique('patient_id')->values();

        $plans = [
            // [days from today, status, hour]
            [2, AppointmentStatus::Scheduled, 10],
            [-5, AppointmentStatus::NoShow, 11],
            [-3, AppointmentStatus::Scheduled, 9],
        ];

        foreach ($plans as $i => [$days, $status, $hour]) {
            $visit = $visits[$i] ?? null;
            if ($visit === null) {
                return;
            }
            // Missed only if the patient has not been back since the review day.
            if ($days < 0 && Visit::query()->where('patient_id', $visit->patient_id)->where('created_at', '>=', Carbon::today()->addDays($days))->exists()) {
                continue;
            }

            $at = Carbon::today()->addDays($days)->setTime($hour, 0);
            $appointment = new Appointment([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $visit->patient_id,
                'origin_visit_id' => $visit->id,
                'doctor_user_id' => $visit->doctor_user_id,
                'department_id' => $visit->department_id,
                'scheduled_at' => $at,
                'ends_at' => $at->copy()->addMinutes(15),
                'duration_minutes' => 15,
                'source' => 'walk_in',
                'status' => $status,
                'reason' => 'Review',
            ]);
            $appointment->forceFill(['hospital_id' => $hospital->id])->save();
        }
    }

    private static function recalls(Hospital $hospital): void
    {
        if (Patient::query()->whereNotNull('next_recall_on')->where('next_recall_on', '>=', Carbon::today()->subDays(120)->toDateString())->count() >= 3) {
            return;
        }

        // Old enough to have had a check-up, with a phone to call.
        $patients = Patient::query()->whereNotNull('dob')->where('dob', '<=', Carbon::today()->subYears(4)->toDateString())
            ->whereNotNull('phone_1')->where('phone_1', '!=', '')
            ->whereNotExists(fn ($q) => $q->from('appointments')->whereColumn('appointments.patient_id', 'patients.id')
                ->where('appointments.scheduled_at', '>=', Carbon::today()))
            ->orderBy('id')->limit(3)->get();

        foreach ([3, -21, -90] as $i => $days) {
            $patient = $patients[$i] ?? null;
            if ($patient === null) {
                return;
            }
            $on = Carbon::today()->addDays($days);
            // "Not back since": only if their last visit was before the date.
            if (Visit::query()->where('patient_id', $patient->id)->where('created_at', '>=', $on)->exists()) {
                continue;
            }
            $patient->forceFill(['next_recall_on' => $on->toDateString()])->saveQuietly();
        }
    }
}
