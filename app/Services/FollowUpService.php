<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\DentalTreatmentStatus;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Models\Appointment;
use App\Models\DentalTreatmentItem;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\Messaging\PatientMessenger;
use App\Support\DoctorSlots;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Follow-up that runs itself (docs/follow-up.md).
 *
 * A review is an appointment booked FROM a visit (`origin_visit_id`): "come
 * back in a week" said in the chair. The visit suggests when, from what was
 * done on it (an extraction suggests 7 days). The receptionist's list, Recalls
 * and reviews due, is built here from three questions: who is due for a review
 * this week, who missed one, and whose dental check-up is due or overdue
 * with nothing booked. From the list, a call is one tap and a text goes
 * through PatientMessenger, which keeps every rule about consent and numbers.
 */
final class FollowUpService
{
    /** A review a patient did not come to is followed up for this long. */
    public const MISSED_DAYS = 60;

    /** A dental check-up overdue for longer than this is no longer chased. */
    public const RECALL_DAYS = 180;

    /** The length of a review appointment, in minutes. */
    public const REVIEW_MINUTES = 15;

    /**
     * What was done, by the words in its name, and how soon to look again.
     * First match wins; the order puts the more specific first.
     *
     * @var list<array{0:list<string>,1:int,2:string}>
     */
    private const RULES = [
        [['extract', 'surgical', 'impact', 'wisdom', 'implant'], 7, 'after an extraction or surgery'],
        [['root canal', 'pulp', 'endodon'], 7, 'to continue the root canal'],
        [['denture'], 7, 'to check the new denture'],
        [['crown', 'bridge'], 14, 'to fit or check the crown'],
        [['brace', 'orthodon'], 28, 'to adjust the braces'],
    ];

    /** When no procedure says otherwise. */
    private const DEFAULT_DAYS = 14;

    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly PatientMessenger $messenger,
    ) {}

    /**
     * When to see this patient again, and why.
     *
     * @return array{days:int, reason:string, date:string}
     */
    public function suggestion(Visit $visit): array
    {
        $done = DentalTreatmentItem::query()
            ->where('done_visit_id', $visit->id)
            ->where('status', DentalTreatmentStatus::Done->value)
            ->with('service:id,name')
            ->get()
            ->map(fn (DentalTreatmentItem $i) => strtolower((string) $i->service?->name))
            ->filter()
            ->all();

        $days = self::DEFAULT_DAYS;
        $reason = 'two weeks after this visit';
        foreach (self::RULES as [$words, $ruleDays, $why]) {
            foreach ($done as $name) {
                foreach ($words as $word) {
                    if (str_contains($name, $word)) {
                        $days = $ruleDays;
                        $reason = $why;
                        break 3;
                    }
                }
            }
        }

        $from = $visit->created_at instanceof Carbon ? $visit->created_at->copy() : Carbon::now();
        $date = $from->startOfDay()->addDays($days);
        if ($date->isPast()) {
            $date = Carbon::today()->addDay();
        }

        return ['days' => $days, 'reason' => $reason, 'date' => $date->toDateString()];
    }

    /** @return Collection<int, Appointment> reviews already booked from this visit */
    public function reviewsFor(Visit $visit): Collection
    {
        return Appointment::query()
            ->where('origin_visit_id', $visit->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->with('doctor:id,name')
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Book the review. The patient comes from the visit; the doctor is the
     * visit's unless another is chosen; the slot rules are the diary's own.
     *
     * @throws \RuntimeException when the visit has no doctor, or the slot is taken
     */
    public function bookReview(Visit $visit, string $date, string $time, ?int $doctorId = null, ?int $by = null): Appointment
    {
        $doctorId ??= $visit->doctor_user_id;
        if ($doctorId === null) {
            throw new \RuntimeException('Choose who will see the patient at the review.');
        }

        // The same review asked for again (a retry after a lost reply, a
        // double press) is the booking already made, not a clash with itself.
        $existing = Appointment::query()
            ->where('origin_visit_id', $visit->id)
            ->where('doctor_user_id', $doctorId)
            ->where('scheduled_at', Carbon::parse($date.' '.$time))
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->appointments->book([
            'origin_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'doctor_user_id' => $doctorId,
            'department_id' => $visit->department_id,
            'scheduled_at' => $date.' '.$time,
            'duration_minutes' => self::REVIEW_MINUTES,
            'source' => 'walk_in',
            'reason' => 'Review',
        ], $by);
    }

    /**
     * The free times for the review booked from a visit, as the visit page
     * offers them. A dental visit books from its case sheet's Follow-up
     * (DentalCaseSheetService::bookReview: the visit's dentist, else whoever
     * books, for REVIEW_MINUTES there), any other visit from Book a review
     * (bookReview above: the visit's doctor). Without a day: a dental visit
     * offers the suggested day or the first day after it the dentist is free,
     * any other visit the suggested day.
     *
     * @return array{kind:string, date:string, doctor_user_id:?int, doctor:?string, minutes:int, times:list<string>, note:?string}
     */
    public function reviewTimes(Visit $visit, User $by, ?string $date = null): array
    {
        $dental = DentalCaseSheetService::appliesTo($visit, app(HospitalSettings::class)->hospital());
        $doctor = $dental ? ($visit->doctor_user_id ?? $by->id) : $visit->doctor_user_id;
        $minutes = $dental ? DentalCaseSheetService::REVIEW_MINUTES : self::REVIEW_MINUTES;

        if ($date === null) {
            $date = $dental
                ? DoctorSlots::firstFreeDay((int) $doctor, app(DentalCaseSheetService::class)->reviewSuggestion($visit)['on'] ?? Carbon::today()->addWeek()->toDateString(), $minutes)
                : $this->suggestion($visit)['date'];
        }

        $times = $doctor === null ? [] : DoctorSlots::open((int) $doctor, $date, $minutes);

        return [
            'kind' => $dental ? 'dental' : 'general',
            'date' => $date,
            'doctor_user_id' => $doctor,
            // The visit's own doctor, or the one booking: never another clinic's.
            'doctor' => $doctor === null ? null : User::query()->whereKey($doctor)->value('name'),
            'minutes' => $minutes,
            'times' => $times,
            'note' => match (true) {
                $times !== [] => null,
                $doctor === null => 'This visit has no doctor yet. Book the review from Appointments instead.',
                default => DoctorSlots::note((int) $doctor, $date, $minutes),
            },
        ];
    }

    // ── The receptionist's list ─────────────────────────────────────────

    public const LISTS = [
        'week' => 'Reviews this week',
        'missed' => 'Missed reviews',
        'recalls' => 'Dental check-ups due',
    ];

    /** The list a request names, or this week's reviews when it names none of them. */
    public static function list(?string $show): string
    {
        return isset(self::LISTS[(string) $show]) ? (string) $show : 'week';
    }

    /**
     * One list's rows, narrowed by what was typed: name, patient number or
     * phone, every word somewhere (Patient::matchWords, the patient search
     * everywhere else). The web page and the API both read it.
     *
     * @return Builder<Appointment>|Builder<Patient>
     */
    public function rows(string $show, string $search = ''): Builder
    {
        $query = match (self::list($show)) {
            'missed' => $this->missedReviews(),
            'recalls' => $this->recallsDue(),
            default => $this->reviewsThisWeek(),
        };

        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        return self::list($show) === 'recalls'
            ? Patient::matchWords($query, $search)
            : $query->whereHas('patient', fn (Builder $p) => Patient::matchWords($p, $search));
    }

    /**
     * What to flag beside a row, worded once for the web page and the app: a
     * check-up overdue by so many days, a review the patient did not come to.
     *
     * @return array{label:string, tone:string}|null
     */
    public function flag(Appointment|Patient $row): ?array
    {
        $today = Carbon::today();

        if ($row instanceof Patient) {
            $on = $row->next_recall_on;
            if ($on === null || ! $on->copy()->startOfDay()->lt($today)) {
                return null;
            }
            $days = (int) round($on->copy()->startOfDay()->diffInDays($today));

            return ['label' => $days.' '.($days === 1 ? 'day' : 'days').' overdue', 'tone' => 'warn'];
        }

        if ($row->status === AppointmentStatus::NoShow) {
            return ['label' => 'Did not come', 'tone' => 'danger'];
        }

        return $row->scheduled_at->lt($today) ? ['label' => 'Not attended', 'tone' => 'danger'] : null;
    }

    /**
     * When each patient was last seen: their newest visit, in one query for a
     * page of rows.
     *
     * @param  list<int>  $patientIds
     * @return array<int, Carbon>
     */
    public function lastVisits(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        return Visit::query()
            ->whereIn('patient_id', array_values(array_unique($patientIds)))
            ->groupBy('patient_id')
            ->selectRaw('patient_id, max(created_at) as last_at')
            ->pluck('last_at', 'patient_id')
            ->filter()
            ->mapWithKeys(fn ($at, $id) => [(int) $id => Carbon::parse((string) $at)])
            ->all();
    }

    /** @return array<string,int> how many in each list */
    public function counts(): array
    {
        return [
            'week' => $this->reviewsThisWeek()->count(),
            'missed' => $this->missedReviews()->count(),
            'recalls' => $this->recallsDue()->count(),
        ];
    }

    /** @return Builder<Appointment> reviews booked for today to seven days on, not yet attended */
    public function reviewsThisWeek(): Builder
    {
        $today = Carbon::today();

        return Appointment::query()
            ->whereNotNull('origin_visit_id')
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->whereBetween('scheduled_at', [$today->copy(), $today->copy()->addDays(7)->endOfDay()])
            ->with(['patient', 'doctor:id,name'])
            ->orderBy('scheduled_at');
    }

    /**
     * Reviews the patient did not come to, in the last MISSED_DAYS: marked
     * no-show, or left booked with the day gone; and not back since, and
     * nothing booked ahead.
     *
     * @return Builder<Appointment>
     */
    public function missedReviews(): Builder
    {
        $today = Carbon::today();

        return Appointment::query()
            ->whereNotNull('origin_visit_id')
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::NoShow->value])
            ->where('scheduled_at', '<', $today)
            ->where('scheduled_at', '>=', $today->copy()->subDays(self::MISSED_DAYS))
            ->whereNotExists(fn ($q) => $q->from('visits')
                ->whereColumn('visits.patient_id', 'appointments.patient_id')
                ->whereColumn('visits.created_at', '>=', 'appointments.scheduled_at'))
            ->whereNotExists(fn ($q) => $q->from('appointments as ahead')
                ->whereColumn('ahead.patient_id', 'appointments.patient_id')
                ->where('ahead.scheduled_at', '>=', $today)
                ->whereNull('ahead.deleted_at')
                ->whereNotIn('ahead.status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value]))
            ->with(['patient', 'doctor:id,name'])
            ->orderByDesc('scheduled_at');
    }

    /**
     * Dental check-ups due in the next seven days or overdue (up to
     * RECALL_DAYS), for patients not back since and with nothing booked.
     * The same rule as the "dental check-ups overdue" insight, over a longer window.
     *
     * @return Builder<Patient>
     */
    public function recallsDue(): Builder
    {
        $today = Carbon::today();

        return Patient::query()
            ->whereNotNull('next_recall_on')
            ->whereDate('next_recall_on', '<=', $today->copy()->addDays(7)->toDateString())
            ->whereDate('next_recall_on', '>=', $today->copy()->subDays(self::RECALL_DAYS)->toDateString())
            ->whereNotExists(fn ($q) => $q->from('visits')
                ->whereColumn('visits.patient_id', 'patients.id')
                ->whereColumn('visits.created_at', '>=', 'patients.next_recall_on'))
            ->whereNotExists(fn ($q) => $q->from('appointments')
                ->whereColumn('appointments.patient_id', 'patients.id')
                ->where('appointments.scheduled_at', '>=', $today)
                ->whereNull('appointments.deleted_at')
                ->whereNotIn('appointments.status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value]))
            ->orderBy('next_recall_on');
    }

    /** Whether this patient's dental check-up is on the list now (due within a week, or overdue). */
    public function recallDue(Patient $patient): bool
    {
        $on = $patient->next_recall_on;

        return $on !== null
            && $on->lte(Carbon::today()->addDays(7))
            && $on->gte(Carbon::today()->subDays(self::RECALL_DAYS));
    }

    /**
     * Text the patient that a review or check-up is due. Once per thing due:
     * pressing again reports the text already sent rather than sending another.
     *
     * @param  string  $key  what is due, e.g. "review:{appointment uuid}" or "recall:2026-10-01"
     * @return array{sent:bool, message:string}
     */
    public function remind(Patient $patient, string $key): array
    {
        $message = $this->messenger->send($patient, 'follow_up_due', [], 'follow_up_due:'.$patient->uuid.':'.$key, MessagePurpose::Care);

        if ($message === null) {
            return ['sent' => false, 'message' => 'Text messages are off for this clinic. Switch them on in Messages to patients, or call instead.'];
        }

        if ($message->status === MessageStatus::Skipped) {
            return ['sent' => false, 'message' => match ($message->skip_reason) {
                'no_consent' => 'Not sent: '.$patient->full_name.' has not agreed to text messages. Call instead.',
                'no_phone' => 'Not sent: there is no phone number for '.$patient->full_name.'.',
                'event_off' => 'Not sent: reminders of a review are switched off in Messages to patients.',
                'no_credit' => 'Not sent: the clinic has no message credit left. Top up, or call instead.',
                default => 'Not sent. Call instead.',
            }];
        }

        if ($message->status === MessageStatus::Failed) {
            return ['sent' => false, 'message' => 'The text to '.$patient->full_name.' could not be delivered. Call instead.'];
        }

        return ['sent' => true, 'message' => $message->wasRecentlyCreated
            ? 'Text sent to '.$patient->full_name.'.'
            : 'A text about this was already sent to '.$patient->full_name.'.'];
    }
}
