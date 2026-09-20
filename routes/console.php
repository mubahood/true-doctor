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

\Illuminate\Support\Facades\Schedule::command('subscriptions:trial-reminders')->dailyAt('08:00');

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

// A job that has failed three times is kept for a week so somebody can look
// at it, then cleared so the table does not grow without limit.
\Illuminate\Support\Facades\Schedule::command('queue:prune-failed --hours=168')
    ->weeklyOn(1, '02:00');

// Sessions expire on their own schedule; the rows do not. Only relevant on
// the database session driver, which is what production uses.
\Illuminate\Support\Facades\Schedule::command('auth:clear-resets')->daily();
