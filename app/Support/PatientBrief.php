<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/**
 * Everything the desk should see the moment a patient is chosen to open a
 * visit for.
 *
 * All of it is the kind of thing that is discovered too late otherwise: a
 * visit already open (so this one would split the bill in two), an
 * appointment today this visit ought to fulfil, money still owed. The web's
 * open-visit dialog and the app's show the same brief from here.
 */
final class PatientBrief
{
    /**
     * @return array{name:string,patient_no:string,meta:string,open_visit:?array{uuid:string,visit_no:string,status:string},appointment:?array{id:int,uuid:string,at:string,doctor:?string,reason:?string},owed:string}
     */
    public static function for(Patient $patient): array
    {
        $open = Visit::where('patient_id', $patient->id)
            ->where('status', '!=', VisitStatus::Completed->value)
            ->latest('id')
            ->first();

        // Today's booking, still to be attended, and not already turned into a
        // visit — one appointment becomes one visit and no more.
        $appointment = Appointment::where('patient_id', $patient->id)
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
            ->whereDate('scheduled_at', Carbon::today())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('visits')
                ->whereColumn('visits.appointment_id', 'appointments.id')
                ->whereNull('visits.deleted_at'))
            ->with('doctor')
            ->orderBy('scheduled_at')
            ->first();

        $owed = Invoice::where('patient_id', $patient->id)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->sum('balance');

        return [
            'name' => trim($patient->first_name.' '.$patient->last_name),
            'patient_no' => (string) $patient->patient_no,
            'meta' => implode(' · ', array_filter([self::age($patient), $patient->sex?->label(), $patient->phone_1])),
            'open_visit' => $open === null ? null : [
                'uuid' => (string) $open->uuid,
                'visit_no' => (string) $open->visit_no,
                'status' => $open->status->label(),
            ],
            'appointment' => $appointment === null ? null : [
                'id' => (int) $appointment->id,
                'uuid' => (string) $appointment->uuid,
                'at' => $appointment->scheduled_at->format('H:i'),
                'doctor' => $appointment->doctor?->name,
                'reason' => $appointment->reason,
            ],
            'owed' => HospitalSettings::decimal($owed, 2),
        ];
    }

    /**
     * Age as a clinician says it.
     *
     * "0 yrs" is how a computer describes a baby. Under two, the months are
     * the whole point — a six-week-old and a twenty-month-old are not the same
     * patient — and after that years are enough.
     */
    public static function age(Patient $patient): ?string
    {
        $dob = $patient->dob;
        if ($dob === null) {
            return null;
        }

        $born = Carbon::parse($dob);
        $months = (int) $born->diffInMonths(Carbon::today());

        if ($months < 1) {
            return max(0, (int) $born->diffInDays(Carbon::today())).' days';
        }

        return $months < 24 ? $months.' mo' : intdiv($months, 12).' yrs';
    }
}
