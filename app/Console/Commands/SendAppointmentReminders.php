<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use App\Services\Messaging\PatientMessenger;
use App\Services\ReminderService;
use App\Support\WallTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reminds about appointments. Runs HOURLY, because a shared host's cron may be
 * late (every 19 minutes on a client's server) and a once-a-day job that was
 * missed was a reminder never sent.
 *
 *  - day before: tomorrow's appointments (the hospital's calendar), from 07:00
 *    that morning. The doctor gets an in-app + push + SMS notice; the patient
 *    (no login) a text through the swappable transport.
 *  - two hours before: the doctor gets an in-app + push notice.
 *
 * Neither goes out between 20:00 and 07:00 hospital time. Each is claimed with
 * a stamp on the appointment (reminder_sent_at / reminder_soon_sent_at) before
 * it is sent, so a late run, an overlapping run or a re-run sends nothing twice.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:remind {--date= : Remind about this date (Y-m-d) instead of tomorrow, at any hour}';

    protected $description = 'Send day-before and two-hours-before reminders for appointments (idempotent; run hourly)';

    private const FROM_HOUR = 7;

    private const UNTIL_HOUR = 20;

    public function handle(ReminderService $reminders): int
    {
        $explicit = $this->option('date');
        $now = CarbonImmutable::now();

        $appointments = Appointment::withoutGlobalScopes()
            ->with(['patient', 'doctor', 'hospital'])
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->when($explicit, fn ($q) => $q->whereBetween('scheduled_at', [CarbonImmutable::parse((string) $explicit)->subDay(), CarbonImmutable::parse((string) $explicit)->addDays(2)]))
            ->unless($explicit, fn ($q) => $q->where('scheduled_at', '>', $now)->where('scheduled_at', '<=', $now->addDays(3)))
            ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhereNull('reminder_soon_sent_at'))
            ->orderBy('scheduled_at')
            ->get();

        $day = 0;
        $soon = 0;
        foreach ($appointments as $appt) {
            $tz = $appt->hospital !== null ? $reminders->zone($appt->hospital) : (string) config('app.timezone');
            $local = $now->setTimezone($tz);
            // The booking is the clinic's wall-clock time (WallTime), not UTC.
            $at = WallTime::at($appt->scheduled_at, $tz);
            $awake = $local->hour >= self::FROM_HOUR && $local->hour < self::UNTIL_HOUR;

            if ($appt->reminder_sent_at === null && ($explicit ? $at->toDateString() === $explicit : ($awake && $at->toDateString() === $local->addDay()->toDateString()))
                && $this->claim($appt, 'reminder_sent_at')) {
                $this->remindDay($appt, $tz);
                $day++;
            }

            if ($appt->reminder_soon_sent_at === null && ! $explicit && $awake
                && $at->lte($local->addHours(2)) && $this->claim($appt, 'reminder_soon_sent_at')) {
                $appt->doctor?->notify(new AppointmentReminder($appt, 'soon'));
                $soon++;
            }
        }

        $this->info("appointments:remind — {$day} day-before, {$soon} two-hour reminder(s).");

        return self::SUCCESS;
    }

    /** Stamp first, send second: the stamp only goes on once, so a send happens at most once. */
    private function claim(Appointment $appt, string $column): bool
    {
        return Appointment::withoutGlobalScopes()->whereKey($appt->getKey())->whereNull($column)->update([$column => now()]) === 1;
    }

    private function remindDay(Appointment $appt, string $tz): void
    {
        $appt->doctor?->notify(new AppointmentReminder($appt, 'day'));

        // The patient: through the messenger, so consent, quiet hours, credit
        // and the message log apply (roadmap 05). Once per appointment.
        if ($appt->patient !== null) {
            app(PatientMessenger::class)->trySend($appt->patient, 'appointment_day_before', [
                'time' => $appt->scheduled_at->format('H:i'),
            ], 'appointment:'.$appt->uuid.':day_before');
        }
    }
}
