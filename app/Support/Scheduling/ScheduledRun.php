<?php

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled job that cannot be missed.
 *
 * Laravel's `dailyAt('03:40')` means "at 03:40 exactly": on a host whose cron
 * fires every 19 minutes (00, 19, 38, 57) that minute never comes and the job
 * never runs. Here a job is due when its most recent scheduled moment is
 * later than its last successful run: it runs at the first tick after its
 * time, and a missed day (a cron that stopped overnight) is caught up at the
 * next tick. Each job must be idempotent, which every one registered this
 * way is (the bed charges bill the difference, prunes delete what is old).
 *
 * The scheduler's own pulse is written here too ({@see tick()}), so the
 * health check can say how long ago it last ran.
 */
final class ScheduledRun
{
    public const TICK = 'scheduler:tick';

    /** Register $event to run once a day, at the first tick at or after $at. */
    public static function daily(Event $event, string $name, string $at): Event
    {
        return self::guard($event, $name, fn (Carbon $now) => self::lastDaily($now, $at));
    }

    /** Once a week on $dayOfWeek (0 = Sunday), at the first tick at or after $at. */
    public static function weekly(Event $event, string $name, int $dayOfWeek, string $at): Event
    {
        return self::guard($event, $name, function (Carbon $now) use ($dayOfWeek, $at) {
            $moment = self::lastDaily($now, $at);
            while ($moment->dayOfWeek !== $dayOfWeek) {
                $moment = $moment->subDay();
            }

            return $moment;
        });
    }

    /** Once a month on $day (1-28), at the first tick at or after $at. */
    public static function monthly(Event $event, string $name, int $day, string $at): Event
    {
        return self::guard($event, $name, function (Carbon $now) use ($day, $at) {
            [$hour, $minute] = array_map('intval', explode(':', $at) + [1 => 0]);
            $moment = $now->copy()->setDate($now->year, $now->month, $day)->setTime($hour, $minute);

            return $moment->gt($now) ? $moment->subMonthNoOverflow() : $moment;
        });
    }

    /** Every $minutes minutes since its last successful run (for a cron that is not every minute). */
    public static function every(Event $event, string $name, int $minutes): Event
    {
        return self::guard($event, $name, fn (Carbon $now) => $now->copy()->subMinutes($minutes)->addSecond());
    }

    /** Is a job whose latest scheduled moment is $moment due, given its last success? */
    public static function isDue(string $name, Carbon $moment): bool
    {
        $last = self::row($name)?->last_succeeded_at;

        return $last === null || Carbon::parse($last)->lt($moment);
    }

    public static function started(string $name): void
    {
        self::write($name, ['last_started_at' => now()], increment: 'runs');
    }

    public static function succeeded(string $name): void
    {
        self::write($name, ['last_succeeded_at' => now(), 'failures_in_a_row' => 0]);
    }

    public static function failed(string $name): void
    {
        self::write($name, ['last_failed_at' => now()], increment: 'failures_in_a_row');
    }

    /** The scheduler ran: called every tick. */
    public static function tick(): void
    {
        self::write(self::TICK, ['last_succeeded_at' => now()]);
    }

    /** When the scheduler last ran, if it ever has. */
    public static function lastTick(): ?Carbon
    {
        $at = self::row(self::TICK)?->last_succeeded_at;

        return $at === null ? null : Carbon::parse($at);
    }

    /** @return list<object> every recorded job, for the health check */
    public static function all(): array
    {
        return Schema::hasTable('scheduled_runs') ? DB::table('scheduled_runs')->orderBy('name')->get()->all() : [];
    }

    /** The most recent moment, at or before $now, that is $at on some day (schedule time zone). */
    public static function lastDaily(Carbon $now, string $at): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $at) + [1 => 0]);
        $moment = $now->copy()->setTime($hour, $minute);

        return $moment->gt($now) ? $moment->subDay() : $moment;
    }

    /** @param callable(Carbon): Carbon $latest */
    private static function guard(Event $event, string $name, callable $latest): Event
    {
        return $event->everyMinute()
            ->name($name)
            ->when(function () use ($name, $latest) {
                $tz = config('app.schedule_timezone') ?: config('app.timezone');

                return self::isDue($name, $latest(now($tz)));
            })
            ->before(fn () => self::started($name))
            ->onSuccess(fn () => self::succeeded($name))
            ->onFailure(fn () => self::failed($name));
    }

    private static function row(string $name): ?object
    {
        if (! Schema::hasTable('scheduled_runs')) {
            return null;
        }

        return DB::table('scheduled_runs')->where('name', $name)->first();
    }

    /** @param array<string,mixed> $values */
    private static function write(string $name, array $values, ?string $increment = null): void
    {
        if (! Schema::hasTable('scheduled_runs')) {
            return;
        }

        $now = now();
        DB::table('scheduled_runs')->insertOrIgnore(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        $query = DB::table('scheduled_runs')->where('name', $name);
        $values['updated_at'] = $now;

        $increment === null ? $query->update($values) : $query->increment($increment, 1, $values);
    }
}
