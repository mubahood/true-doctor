<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\TrialEnding;
use Illuminate\Console\Command;

/**
 * Reminds hospital owners whose free trial ends within N days (default 3) to
 * activate a plan. Schedule daily. Idempotency for "once per trial" is left to
 * the notification layer / a future sent-marker; at daily cadence with a small
 * window this is acceptable for v1.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'subscriptions:trial-reminders {--days=3 : Remind when the trial ends within this many days}';

    protected $description = 'Notify hospitals whose trial is ending soon';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $now = now();
        $windowEnd = $now->copy()->addDays($days);

        $subscriptions = Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $windowEnd])
            ->get();

        $count = 0;
        foreach ($subscriptions as $sub) {
            $daysLeft = max(0, (int) $now->diffInDays($sub->trial_ends_at));
            $admins = User::where('hospital_id', $sub->hospital_id)->where('role', 'hospital_admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new TrialEnding($sub, $daysLeft));
                $count++;
            }
        }

        $this->info("subscriptions:trial-reminders — {$count} reminder(s) sent.");

        return self::SUCCESS;
    }
}
