<?php

namespace Database\Seeders\DemoScenarios;

use App\Models\Hospital;
use App\Models\OwnerSummary as OwnerSummaryRow;
use App\Models\User;
use App\Services\OwnerSummaryService;
use Illuminate\Support\Carbon;

/**
 * The owner's daily summary in the demo (roadmap 09 §2): the demo hospital's
 * administrator has it on, by email at 07:30, and the last three mornings'
 * summaries are in the log, composed from the demo's own figures for those
 * days. Nothing is sent. Safe to run twice: one row per owner per day.
 */
final class OwnerSummary
{
    public const DAYS = 3;

    public static function run(Hospital $hospital): void
    {
        $owner = User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')
            ->where('is_active', true)->orderBy('id')->first();
        if ($owner === null || trim((string) $owner->email) === '') {
            return;
        }

        $service = app(OwnerSummaryService::class);
        $service->savePreferences($owner, ['enabled' => true, 'channel' => 'email', 'at' => OwnerSummaryService::DEFAULT_AT]);
        $channel = $service->channel('email');

        for ($i = self::DAYS; $i >= 1; $i--) {
            $date = $service->yesterday($hospital, Carbon::now()->subDays($i - 1));
            $exists = OwnerSummaryRow::query()->withoutGlobalScopes()
                ->where('user_id', $owner->id)->whereDate('summary_date', $date)->exists();
            if ($exists) {
                continue;
            }

            $s = $service->compose($hospital, $owner, $date);
            $sentAt = Carbon::parse($date)->addDay()->setTimeFromTimeString(OwnerSummaryService::DEFAULT_AT);

            $row = new OwnerSummaryRow([
                'user_id' => $owner->id,
                'summary_date' => $date,
                'channel' => 'email',
                'recipient' => $owner->email,
                'body' => $channel->format($s['subject'], $s['lines'], $s['link']),
                'status' => OwnerSummaryRow::SENT,
                'attempts' => 1,
                'sent_at' => $sentAt,
            ]);
            $row->forceFill(['hospital_id' => $hospital->id])->save();
        }
    }
}
