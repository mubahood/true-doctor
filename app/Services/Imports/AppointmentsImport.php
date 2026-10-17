<?php

namespace App\Services\Imports;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Appointments already booked in the clinic's old diary, for patients who are
 * registered (import the patients first). Booked through AppointmentService,
 * so a clash with another booking of the same doctor is refused as it would be
 * at the desk; the doctor's hours set up here are not imposed on a booking
 * made before they existed, and nobody is texted about it.
 */
class AppointmentsImport extends ImportHandler
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function fields(): array
    {
        return [
            new ImportField('patient_no', 'Patient number', 'Their patient number here, or the old file number you imported them with.', 'PT 1043', false,
                ['patient no', 'patient number', 'file no', 'file number', 'old file no', 'old file number', 'patient id', 'opd no', 'card no', 'reg no', 'id']),
            new ImportField('patient_name', 'Patient name', 'Used to find them when there is no number, and to check the right person was found.', 'Amina Nakato', false,
                ['patient', 'name', 'names', 'client', 'client name', 'full name', 'patients name']),
            new ImportField('phone', 'Phone', 'Used to find them when there is no number.', '0772 123456', false,
                ['tel', 'telephone', 'contact', 'mobile', 'phone no', 'phone number']),
            new ImportField('date', 'Date', 'Day first: 25/11/2026. Only appointments from today on.', today()->addDays(7)->format('d/m/Y'), true,
                ['appointment date', 'day', 'appt date', 'booked for', 'date of appointment', 'appointment']),
            new ImportField('time', 'Time', '14:30 or 2:30 pm.', '10:30', true,
                ['appointment time', 'at', 'start', 'start time', 'hour', 'appt time']),
            new ImportField('duration', 'Minutes', 'How long it is booked for. Empty means 30.', '30', false,
                ['duration', 'length', 'mins', 'duration minutes', 'duration mins']),
            new ImportField('doctor', 'Doctor', 'The name of the doctor or dentist as it is on their account here.', '', true,
                ['dentist', 'clinician', 'with', 'seen by', 'provider', 'staff', 'consultant', 'doctor name', 'dr']),
            new ImportField('reason', 'Reason', 'What it is for.', 'Review after extraction', false,
                ['purpose', 'procedure', 'notes', 'for', 'visit reason', 'comment', 'service', 'treatment']),
        ];
    }

    public function decisions(): array
    {
        return ['skip', 'add'];
    }

    public function requiredColumns(): array
    {
        return [['patient_no', 'patient_name', 'phone'], ['date'], ['doctor']];
    }

    public function check(array $v): array
    {
        $problems = [];

        $patient = $this->patient($v, $problems);
        $doctor = $this->doctor($v['doctor'] ?? '', $problems);
        $start = $this->start($v, $problems);

        $duration = trim($v['duration'] ?? '');
        if ($duration !== '' && (! ctype_digit($duration) || (int) $duration < 5 || (int) $duration > 480)) {
            $problems[] = 'Minutes "'.$duration.'" should be a whole number from 5 to 480.';
        }
        if (mb_strlen($v['reason'] ?? '') > 255) {
            $problems[] = 'The reason is longer than 255 characters.';
        }

        $match = null;
        if ($problems === [] && $patient !== null && $start !== null && $doctor !== null) {
            $hit = Appointment::query()->where('patient_id', $patient->id)->where('scheduled_at', $start->format('Y-m-d H:i:s'))
                ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])->first();
            if ($hit !== null) {
                $match = ['id' => $hit->id, 'reason' => 'Already booked at this time', 'label' => $patient->full_name.', '.$start->format('j M Y H:i')];
            }
        }

        return ['problems' => $problems, 'match' => $match];
    }

    public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array
    {
        $problems = [];
        $patient = $this->patient($v, $problems);
        $doctor = $this->doctor($v['doctor'] ?? '', $problems);
        $start = $this->start($v, $problems);
        if ($problems !== [] || $patient === null || $doctor === null || $start === null) {
            throw new RuntimeException($problems[0] ?? 'This row could not be read.');
        }

        $duration = trim($v['duration'] ?? '');
        $appointment = $this->appointments->book([
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => $start->format('Y-m-d H:i:s'),
            'duration_minutes' => $duration !== '' ? (int) $duration : 30,
            'source' => AppointmentSource::Phone->value,
            'reason' => ($v['reason'] ?? '') !== '' ? $v['reason'] : null,
        ], $by, imported: true);

        return ['status' => ImportRow::ADDED, 'type' => Appointment::class, 'id' => $appointment->id];
    }

    public function undoBlocker(ImportRow $row): ?string
    {
        $appointment = Appointment::query()->find($row->record_id);
        if ($appointment === null) {
            return null;
        }

        return match (true) {
            $appointment->status !== AppointmentStatus::Scheduled => 'Is now '.strtolower($appointment->status->label()).'.',
            $row->updated_at !== null && $appointment->updated_at !== null && $appointment->updated_at->gt($row->updated_at) => 'Was moved or changed since.',
            default => null,
        };
    }

    public function undo(ImportRow $row, ?int $by): void
    {
        Appointment::query()->find($row->record_id)?->delete();
    }

    // ── Reading a row ──────────────────────────────────────────────────

    /** @param list<string> $problems */
    private function patient(array $v, array &$problems): ?Patient
    {
        $number = trim($v['patient_no'] ?? '');
        $name = trim($v['patient_name'] ?? '');
        $phone = trim($v['phone'] ?? '');

        if ($number !== '') {
            $found = Patient::query()->where(fn (Builder $q) => $q->where('patient_no', $number)->orWhere('old_file_no', $number))->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
            $problems[] = $found->isEmpty()
                ? 'No registered patient has the number "'.$number.'". Import or register the patient first.'
                : 'Two patients have the number "'.$number.'". Use the patient number from this system.';

            return null;
        }

        $tail = PhoneNumber::tail($phone);
        if ($tail !== null) {
            $found = Patient::query()->where(fn (Builder $q) => $q->where('phone_1', 'like', "%{$tail}")->orWhere('phone_2', 'like', "%{$tail}"))->limit(5)->get();
            if ($name !== '' && $found->count() > 1) {
                $found = $found->filter(fn (Patient $p) => $this->same($p->full_name, $name))->values();
            }
            if ($found->count() === 1) {
                return $found->first();
            }
            if ($found->count() > 1) {
                $problems[] = 'More than one patient has the phone "'.$phone.'". Add the patient number.';

                return null;
            }
        }

        if ($name !== '') {
            // "Amina Nakato" or "Nakato Amina": the first word is either name.
            $words = preg_split('/\s+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $head = (string) array_shift($words);
            $rest = implode(' ', $words);
            $lastWord = (string) array_pop($words);
            $before = trim($head.' '.implode(' ', $words));
            $found = Patient::query()->where(fn (Builder $q) => $q
                ->where(fn (Builder $x) => $x->whereRaw('lower(first_name) = ?', [$head])->whereRaw('lower(last_name) = ?', [$rest]))
                ->orWhere(fn (Builder $x) => $x->whereRaw('lower(last_name) = ?', [$head])->whereRaw('lower(first_name) = ?', [$rest]))
                ->orWhere(fn (Builder $x) => $x->whereRaw('lower(first_name) = ?', [$before])->whereRaw('lower(last_name) = ?', [$lastWord])))
                ->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
            $problems[] = $found->isEmpty()
                ? 'No registered patient is called "'.$name.'". Import or register the patient first.'
                : 'More than one patient is called "'.$name.'". Add the patient number.';

            return null;
        }

        $problems[] = 'There is no patient number, phone or name to find the patient by.';

        return null;
    }

    /** @param list<string> $problems */
    private function doctor(string $value, array &$problems): ?User
    {
        $value = trim($value);
        if ($value === '') {
            $problems[] = 'There is no doctor.';

            return null;
        }
        $plain = mb_strtolower(trim((string) preg_replace('/^(dr\.?|doctor)\s+/i', '', $value)));

        // Staff of THIS hospital only: users carry no tenant scope of their own.
        $found = User::query()->currentHospital()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereRaw('lower(name) = ?', [$plain])
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->orWhereRaw('lower(email) = ?', [mb_strtolower($value)])
                ->orWhereRaw('lower(name) = ?', ['dr '.$plain])
                ->orWhereRaw('lower(name) = ?', ['dr. '.$plain]))
            ->limit(2)->get();

        if ($found->count() === 1) {
            return $found->first();
        }
        $problems[] = $found->isEmpty()
            ? 'No staff member here is called "'.$value.'". Check the spelling against Staff, or add them first.'
            : 'More than one staff member is called "'.$value.'". Use their email address instead.';

        return null;
    }

    /** @param list<string> $problems */
    private function start(array $v, array &$problems): ?Carbon
    {
        $date = $this->date($v['date'] ?? '');
        if ($date === null) {
            $problems[] = 'There is no date.';

            return null;
        }
        if ($date === false) {
            $problems[] = 'Date "'.$v['date'].'" is not a date we can read. Write it like 25/11/2026.';

            return null;
        }

        // A time in its own column, or after the date in the same cell.
        $time = $this->time($v['time'] ?? '');
        if ($time === null && preg_match('/\s(\d{1,2}:\d{2}\s*(am|pm)?)/i', $v['date'] ?? '', $m) === 1) {
            $time = $this->time($m[1]);
        }
        if ($time === false) {
            $problems[] = 'Time "'.$v['time'].'" is not a time we can read. Write it like 14:30.';

            return null;
        }
        if ($time === null) {
            $problems[] = 'There is no time.';

            return null;
        }

        $start = Carbon::parse($date.' '.$time.':00');
        if ($start->lt(today())) {
            $problems[] = 'The date '.$start->format('j M Y').' has passed. Only appointments from today on are imported.';

            return null;
        }

        return $start;
    }
}
