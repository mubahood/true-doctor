<?php

namespace Tests\Feature;

use App\Support\Scheduling\ScheduledRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Scheduled jobs on a cron that fires every 19 minutes (00, 19, 38, 57). */
class ScheduledRunTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $name): Event
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === $name);
        $this->assertNotNull($event, "no scheduled job named {$name}");

        return $event;
    }

    private function due(string $name): bool
    {
        $event = $this->event($name);

        return $event->isDue($this->app) && $event->filtersPass($this->app);
    }

    public function test_the_last_daily_moment(): void
    {
        $this->assertSame('2026-10-17 03:40', ScheduledRun::lastDaily(Carbon::parse('2026-10-17 03:57'), '03:40')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-16 03:40', ScheduledRun::lastDaily(Carbon::parse('2026-10-17 03:38'), '03:40')->format('Y-m-d H:i'));
    }

    public function test_a_job_pinned_to_0340_runs_at_the_0357_tick_and_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 03:38', config('app.timezone')));
        ScheduledRun::succeeded('sync:prune-ledger'); // yesterday's run, as far as it knows
        $this->travelTo(Carbon::parse('2026-10-17 03:38:30'));
        $this->assertFalse($this->due('sync:prune-ledger'), 'not before its time');

        $this->travelTo(Carbon::parse('2026-10-17 03:57'));
        $this->assertTrue($this->due('sync:prune-ledger'), 'the first tick after 03:40');

        ScheduledRun::succeeded('sync:prune-ledger');
        $this->travelTo(Carbon::parse('2026-10-17 04:19'));
        $this->assertFalse($this->due('sync:prune-ledger'), 'once a day');

        $this->travelTo(Carbon::parse('2026-10-18 03:57'));
        $this->assertTrue($this->due('sync:prune-ledger'), 'and again the next day');
    }

    public function test_a_day_the_cron_missed_is_caught_up_at_the_next_tick_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 00:19'));
        ScheduledRun::succeeded('admissions-accrue-bed-charges');

        // The host was down from the 14th to the morning of the 17th.
        $this->travelTo(Carbon::parse('2026-10-17 09:19'));
        $this->assertTrue($this->due('admissions-accrue-bed-charges'));
        ScheduledRun::succeeded('admissions-accrue-bed-charges');

        $this->travelTo(Carbon::parse('2026-10-17 09:38'));
        $this->assertFalse($this->due('admissions-accrue-bed-charges'), 'one catch-up run; the command bills every missed night itself');
    }

    public function test_a_failed_run_is_tried_again_at_the_next_tick(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 03:57'));
        ScheduledRun::started('traffic-prune');
        ScheduledRun::failed('traffic-prune');

        $this->travelTo(Carbon::parse('2026-10-17 04:19'));
        $this->assertTrue($this->due('traffic-prune'));
        $row = collect(ScheduledRun::all())->firstWhere('name', 'traffic-prune');
        $this->assertSame(1, (int) $row->failures_in_a_row);
    }

    public function test_every_n_minutes_counts_from_the_last_run(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00'));
        ScheduledRun::succeeded('sync:reclaim-stale');

        $this->travelTo(Carbon::parse('2026-10-17 10:13'));
        $this->assertFalse($this->due('sync:reclaim-stale'));
        $this->travelTo(Carbon::parse('2026-10-17 10:19'));
        $this->assertTrue($this->due('sync:reclaim-stale'), 'a 19-minute cron still reclaims every tick after 15 minutes');
    }

    public function test_no_job_is_pinned_to_a_minute_a_19_minute_cron_skips(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            [$minute] = explode(' ', $event->expression);
            $this->assertTrue(
                in_array($minute, ['*', '0'], true),
                "{$event->description} ({$event->expression}) would be missed by a cron that fires at 00, 19, 38 and 57",
            );
        }
    }

    public function test_the_scheduler_records_its_ticks(): void
    {
        $this->assertNull(ScheduledRun::lastTick());

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(ScheduledRun::lastTick());
    }
}
