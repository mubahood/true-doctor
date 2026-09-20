<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use App\Services\Channels\VerificationChannel;
use Illuminate\Console\Command;

/**
 * Sends reminders for appointments happening on a target date (default:
 * tomorrow). The doctor (a user) gets an in-app + SMS notification; the patient
 * — who has no login — gets a direct SMS via the swappable transport. Schedule
 * this daily in routes/console.php.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:remind {--date= : Target date (Y-m-d), defaults to tomorrow}';

    protected $description = 'Send reminders for upcoming appointments';

    public function handle(VerificationChannel $sms): int
    {
        $date = $this->option('date') ?: now()->addDay()->toDateString();

        $appointments = Appointment::withoutGlobalScopes()
            ->with(['patient', 'doctor'])
            ->whereDate('scheduled_at', $date)
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->get();

        $count = 0;
        foreach ($appointments as $appt) {
            if ($appt->doctor !== null) {
                $appt->doctor->notify(new AppointmentReminder($appt));
            }
            $phone = $appt->patient?->phone_1;
            if ($phone) {
                $sms->send((string) $phone, "Reminder: your appointment is on {$appt->scheduled_at->format('d M Y \a\t H:i')}.");
            }
            $count++;
        }

        $this->info("appointments:remind — {$count} reminder(s) for {$date}.");

        return self::SUCCESS;
    }
}
