<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Services\DentalService;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PatientMessenger;
use App\Services\ReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The messages that are due by the calendar rather than by something a person
 * did, run hourly (a late cron loses nothing):
 *
 *  - a dental check-up recall on the day it falls due, or within a week after
 *    if the server was down that day (once per due date: the dedupe key);
 *  - a message left "sending" for over half an hour by a worker that died is
 *    marked failed, so the log never shows one as forever on its way.
 */
class SendDueMessages extends Command
{
    protected $signature = 'messages:due';

    protected $description = 'Queue recall messages that are due and close messages stuck in sending (idempotent; run hourly)';

    public function handle(PatientMessenger $messenger, ReminderService $zones): int
    {
        $recalls = 0;

        foreach (Hospital::query()->get() as $hospital) {
            if (! MessagingSettings::of($hospital)->smsEnabled() || ! DentalService::enabledFor($hospital)) {
                continue;
            }

            $today = Carbon::now($zones->zone($hospital))->toDateString();
            $due = Patient::query()->withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->whereNotNull('next_recall_on')
                // whereDate: the column may hold a time of midnight, and "2026-10-16 00:00:00" sorts after "2026-10-16".
                ->whereDate('next_recall_on', '>=', Carbon::parse($today)->subDays(7)->toDateString())
                ->whereDate('next_recall_on', '<=', $today)
                ->get();

            foreach ($due as $patient) {
                $sent = $messenger->send($patient, 'dental_recall', [], 'dental_recall:'.$patient->uuid.':'.$patient->next_recall_on?->toDateString());
                $recalls += $sent !== null && $sent->wasRecentlyCreated ? 1 : 0;
            }
        }

        $stuck = PatientMessage::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Sending->value)
            ->where('updated_at', '<', Carbon::now()->subMinutes(30))
            ->update(['status' => MessageStatus::Failed->value, 'error' => 'The send was interrupted; whether it reached the phone is not known.']);

        $this->info("messages:due: {$recalls} recall(s) queued, {$stuck} stuck message(s) closed.");

        return self::SUCCESS;
    }
}
