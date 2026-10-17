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
| The live host's cron fires every 19 minutes, not every minute, so a job
| pinned to a minute ("03:40") would never run. Every daily and weekly job is
| registered through ScheduledRun: it runs at the first tick at or after its
| time, and a day the cron missed is caught up at the next tick. Each tick is
| recorded, so the health check can say when the scheduler last ran.
*/

\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Scheduling\ScheduledRun::tick())
    ->everyMinute()->name('scheduler-tick');

// Hourly, not daily: a shared host's cron can be late or skip a run, and each
// reminder is stamped on the appointment so a run never repeats another's.
\Illuminate\Support\Facades\Schedule::command('appointments:remind')->hourly()->withoutOverlapping(10);
// Recall messages that fall due by the calendar, and messages stuck in sending (idempotent).
\Illuminate\Support\Facades\Schedule::command('messages:due')->hourly()->withoutOverlapping(10);

\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\Cache::put('standalone:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(5));
})->everyMinute()->name('standalone-scheduler-heartbeat')
    ->when(fn () => \App\Support\Edition::isStandalone());

// A standalone installation looks after itself: whatever the Licence &
// updates page asked for, a nightly backup, and a daily look for updates.
\Illuminate\Support\Facades\Schedule::command('standalone:system-requests')
    ->everyMinute()->name('standalone-system-requests')->withoutOverlapping(60)->runInBackground()
    ->when(fn () => \App\Support\Edition::isStandalone());
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('standalone:backup'), 'standalone-backup', '02:15')
    ->withoutOverlapping(120)
    ->when(fn () => \App\Support\Edition::isStandalone());
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('standalone:update --check'), 'standalone-update-check', '06:10')
    ->when(fn () => \App\Support\Edition::isStandalone() && filled(config('edition.update_server')));

// The online service's own backups: nightly, encrypted, off the server; and
// once a month the newest is restored into a scratch database to prove it
// (config/backup.php). A standalone copy has standalone:backup instead.
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('td:backup'), 'td-backup', '01:30')
    ->withoutOverlapping(120)
    ->when(fn () => \App\Support\Edition::isSaas() && filled(config('backup.encryption_key')));
\App\Support\Scheduling\ScheduledRun::monthly(\Illuminate\Support\Facades\Schedule::command('td:backup-drill'), 'td-backup-drill', 2, '05:00')
    ->withoutOverlapping(120)
    ->when(fn () => \App\Support\Edition::isSaas() && filled(config('backup.encryption_key')));

\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('subscriptions:trial-reminders'), 'subscriptions-trial-reminders', '08:00')
    ->when(fn () => \App\Support\Edition::isSaas());

// Offline sync ledger. A claim left `processing` by a process that died is
// marked retryable, so the device's next attempt runs instead of waiting; and
// results older than the replay window (90 days) are dropped. Both existed and
// were never called.
\App\Support\Scheduling\ScheduledRun::every(\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Services\Sync\OperationLedger::class)->reclaimStale()), 'sync:reclaim-stale', 15)
    ->withoutOverlapping();
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Services\Sync\OperationLedger::class)->prune()), 'sync:prune-ledger', '03:40')
    ->withoutOverlapping();

// Tombstones (what devices must forget) are kept 90 days. Every-minute entry
// with its own once-per-12-hours guard, so a cron that fires only every 19
// minutes still prunes (see SyncTombstone::pruneIfDue).
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Models\SyncTombstone::pruneIfDue())
    ->everyMinute()->name('sync:prune-tombstones')->withoutOverlapping();

// Traffic retention: crawler rows after 90 days, unconverted visitors after
// 400. A visit that became a hospital is never pruned — see TrafficSession.
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('model:prune', [
    '--model' => [\App\Models\TrafficEvent::class, \App\Models\TrafficSession::class],
]), 'traffic-prune', '03:30')->withoutOverlapping();

// Last night, onto every open stay's bill. Just after midnight, so a night is
// billed the moment it is complete and a ward round in the morning already
// sees it. Idempotent (it bills the nights completed minus the nights already
// billed), so a night the cron missed is billed at the next tick, once.
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('admissions:accrue-bed-charges'), 'admissions-accrue-bed-charges', '00:05')
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

\App\Support\Scheduling\ScheduledRun::every(\Illuminate\Support\Facades\Schedule::call(function (): void {
    \App\Models\StandaloneRelease::where('status', 'building')
        ->where('updated_at', '<', now()->subMinutes(40))
        ->update(['status' => 'failed', 'progress' => 'Release worker stopped unexpectedly', 'failure' => 'The build worker did not finish. Review its logs, then stage a new version.']);
}), 'standalone-release-stale-check', 10)->when(fn () => \App\Support\Edition::isSaas());

// A job that has failed three times is kept for a week so somebody can look
// at it, then cleared so the table does not grow without limit.
\App\Support\Scheduling\ScheduledRun::weekly(\Illuminate\Support\Facades\Schedule::command('queue:prune-failed --hours=168'), 'queue-prune-failed', 1, '02:00');

// Sessions expire on their own schedule; the rows do not. Only relevant on
// the database session driver, which is what production uses.
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('auth:clear-resets'), 'auth-clear-resets', '00:00');

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
    \App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::command('demo:reset --force'), 'demo-reset', (string) config('demo.reset.at', '03:30'))
        ->withoutOverlapping();
}

// Stored answers for Idempotency-Key retries are good for 24 hours.
\App\Support\Scheduling\ScheduledRun::daily(\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\DB::table('idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
}), 'idempotency-keys-prune', '03:30');
