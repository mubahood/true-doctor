<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\MessageStatus;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Services\Messaging\ConsentService;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Messages to patients on the demo hospital: texts switched on, a bundle of
 * credit, most patients with a phone agreeing to care messages (a few also to
 * news, a few refusing), and a month of reminders and receipts in the log,
 * one of them failed, so Settings → Messages shows what a working clinic sees.
 */
final class PatientMessages
{
    public static function run(Hospital $hospital): void
    {
        if (PatientMessage::query()->exists()) {
            return; // already there
        }

        MessagingSettings::save($hospital, ['sms' => true, 'events' => array_fill_keys(array_keys(MessagingSettings::EVENTS), true), 'quiet_start' => '20:00', 'quiet_end' => '07:00']);
        app(MessageCredits::class)->post($hospital->id, 'grant', 500, 'DEMO-BUNDLE', null, null, 'Demo bundle');

        $consents = app(ConsentService::class);
        $patients = Patient::query()->whereNotNull('phone_1')->where('phone_1', '!=', '')->orderBy('id')->get();
        foreach ($patients as $i => $p) {
            $care = $i % 7 !== 3;
            $consents->record($p, ['sms.care' => $care, 'whatsapp.care' => $care, 'sms.marketing' => $care && $i % 4 === 0]);
        }

        $events = [
            ['appointment_day_before', 'Reminder: your appointment at '.$hospital->name.' is tomorrow at 10:30. Reply STOP to stop messages.'],
            ['appointment_booked', 'Your appointment at '.$hospital->name.' is booked. Reply STOP to stop messages.'],
            ['receipt', 'Thank you. We received your payment at '.$hospital->name.'. Reply STOP to stop messages.'],
        ];
        foreach ($patients->take(24)->values() as $i => $p) {
            if ($i % 7 === 3) {
                continue; // refused
            }
            [$event, $body] = $events[$i % 3];
            $at = Carbon::now()->subDays(28 - $i)->setTime(9, 15);
            $failed = $i === 5;
            PatientMessage::query()->create([
                'uuid' => (string) Str::uuid(), 'patient_id' => $p->id, 'channel' => 'sms', 'purpose' => 'care', 'event' => $event,
                'dedupe_key' => 'demo:'.$event.':'.$p->id, 'to_phone' => \App\Support\PhoneNumber::e164($p->phone_1) ?? $p->phone_1, 'body' => $body,
                'status' => $failed ? MessageStatus::Failed : ($i % 2 ? MessageStatus::Delivered : MessageStatus::Sent),
                'error' => $failed ? 'Number not reachable' : null, 'provider' => 'log', 'segments' => 1, 'attempts' => 1,
                'sent_at' => $failed ? null : $at, 'delivered_at' => $failed || $i % 2 === 0 ? null : $at->copy()->addMinute(),
            ])->forceFill(['created_at' => $at])->save();
        }
    }
}
