<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| The True-Doctor registry expiry sweep/reminders retired with the registry
| domain. HMS scheduled tasks (subscription expiry, low-stock alerts,
| appointment reminders, …) are added per HMS_PLAN.md as their modules land.
*/

\Illuminate\Support\Facades\Schedule::command('appointments:remind')->dailyAt('07:00');

\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\Cache::put('standalone:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(5));
})->everyMinute()->name('standalone-scheduler-heartbeat')
    ->when(fn () => \App\Support\Edition::isStandalone());

// A standalone installation looks after itself: whatever the Licence &
// updates page asked for, a nightly backup, and a daily look for updates.
\Illuminate\Support\Facades\Schedule::command('standalone:system-requests')
    ->everyMinute()->name('standalone-system-requests')->withoutOverlapping(60)->runInBackground()
    ->when(fn () => \App\Support\Edition::isStandalone());
\Illuminate\Support\Facades\Schedule::command('standalone:backup')->dailyAt('02:15')
    ->name('standalone-backup')->withoutOverlapping(120)
    ->when(fn () => \App\Support\Edition::isStandalone());
\Illuminate\Support\Facades\Schedule::command('standalone:update --check')->dailyAt('06:10')
    ->name('standalone-update-check')
    ->when(fn () => \App\Support\Edition::isStandalone() && filled(config('edition.update_server')));

\Illuminate\Support\Facades\Schedule::command('subscriptions:trial-reminders')->dailyAt('08:00')
    ->when(fn () => \App\Support\Edition::isSaas());

// Offline sync ledger. A claim left `processing` by a process that died is
// marked retryable, so the device's next attempt runs instead of waiting; and
// results older than the replay window (90 days) are dropped. Both existed and
// were never called.
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Services\Sync\OperationLedger::class)->reclaimStale())
    ->everyFifteenMinutes()->name('sync:reclaim-stale')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Services\Sync\OperationLedger::class)->prune())
    ->dailyAt('03:40')->name('sync:prune-ledger')->withoutOverlapping();

// Traffic retention: crawler rows after 90 days, unconverted visitors after
// 400. A visit that became a hospital is never pruned — see TrafficSession.
\Illuminate\Support\Facades\Schedule::command('model:prune', [
    '--model' => [\App\Models\TrafficEvent::class, \App\Models\TrafficSession::class],
])->dailyAt('03:30')->withoutOverlapping();

// Last night, onto every open stay's bill. Just after midnight, so a night is
// billed the moment it is complete and a ward round in the morning already
// sees it. Idempotent — see App\Console\Commands\AccrueBedCharges.
\Illuminate\Support\Facades\Schedule::command('admissions:accrue-bed-charges')
    ->dailyAt('00:05')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| The queue
|--------------------------------------------------------------------------
| Every notification in this system implements ShouldQueue, so that a mail
| provider having a bad afternoon cannot turn somebody's sign-up into a 500.
| The other half of that bargain is that SOMETHING has to drain the queue —
| without this, mail is accepted and then silently never sent, which is worse
| than failing loudly.
|
| A scheduled short-lived worker rather than a daemon, because the production
| host is shared cPanel: there is no supervisor, and a long-running process
| would be killed without notice and never restarted.
|
|   --stop-when-empty  finish the backlog and exit, rather than idling
|   --max-time=55      never overlap the next minute's run
|   --tries / --backoff  three attempts, spaced, then onto failed_jobs
|
| withoutOverlapping() is belt as well as braces: if one run does hang, the
| next minute does not start a second worker on the same queue.
*/
\Illuminate\Support\Facades\Schedule::command(
    'queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30 --quiet'
)->everyMinute()->withoutOverlapping();

// Release builds are deliberately separate from the short notification queue:
// they can take up to 30 minutes and need a longer database lease. The worker
// is still launched by cPanel's existing once-per-minute scheduler cron.
\Illuminate\Support\Facades\Schedule::command(
    'queue:work release-builds --queue=releases --stop-when-empty --max-time=1850 --timeout=1800 --tries=1 --quiet'
)->everyMinute()->name('standalone-release-worker')->withoutOverlapping(35)->runInBackground()
    ->when(fn () => \App\Support\Edition::isSaas());

\Illuminate\Support\Facades\Schedule::call(function (): void {
    \App\Models\StandaloneRelease::where('status', 'building')
        ->where('updated_at', '<', now()->subMinutes(40))
        ->update(['status' => 'failed', 'progress' => 'Release worker stopped unexpectedly', 'failure' => 'The build worker did not finish. Review its logs, then stage a new version.']);
})->everyTenMinutes()->name('standalone-release-stale-check')->when(fn () => \App\Support\Edition::isSaas());

// A job that has failed three times is kept for a week so somebody can look
// at it, then cleared so the table does not grow without limit.
\Illuminate\Support\Facades\Schedule::command('queue:prune-failed --hours=168')
    ->weeklyOn(1, '02:00');

// Sessions expire on their own schedule; the rows do not. Only relevant on
// the database session driver, which is what production uses.
\Illuminate\Support\Facades\Schedule::command('auth:clear-resets')->daily();

/*
|--------------------------------------------------------------------------
| The demonstration hospital
|--------------------------------------------------------------------------
| A public demo is a hospital that strangers change. Left alone it fills with
| patients called "aaa" and every bed ends up occupied, and the next person
| who comes to look at the product sees a mess instead.
|
| Registered only when BOTH switches are on, so turning the demo on cannot by
| itself start something that deletes rows on a schedule. The command has its
| own guards besides — see App\Console\Commands\ResetDemoHospital.
*/
if (\App\Support\Edition::isSaas() && config('demo.enabled') && config('demo.reset.enabled')) {
    \Illuminate\Support\Facades\Schedule::command('demo:reset --force')
        ->dailyAt((string) config('demo.reset.at', '03:30'))
        ->withoutOverlapping();
}

// Stored answers for Idempotency-Key retries are good for 24 hours.
\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\DB::table('idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
})->dailyAt('03:30')->name('idempotency-keys-prune');
