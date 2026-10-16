<?php

namespace Tests\Feature;

use App\Support\Health\HealthCheck;
use App\Support\Scheduling\ScheduledRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function checkStatus(string $key): ?string
    {
        return collect(app(HealthCheck::class)->run())->firstWhere('key', $key)['status'] ?? null;
    }

    public function test_a_scheduler_that_never_ran_or_stopped_fails_and_a_slow_one_warns(): void
    {
        $this->assertSame('fail', $this->checkStatus('scheduler'));

        ScheduledRun::tick();
        $this->assertSame('ok', $this->checkStatus('scheduler'));

        $this->travel(19)->minutes();
        $this->assertSame('warn', $this->checkStatus('scheduler'), 'a 19-minute cron is slower than reminders need');

        $this->travel(2)->hours();
        $this->assertSame('fail', $this->checkStatus('scheduler'));
    }

    public function test_backups_fail_without_a_key_and_warn_when_they_never_leave_the_server(): void
    {
        config(['backup.encryption_key' => null]);
        $this->assertSame('fail', $this->checkStatus('backup'));

        config(['backup.encryption_key' => \App\Support\Backups\BackupCipher::newKey()]);
        \App\Support\Settings::set('backup_last', json_encode(['at' => now()->toIso8601String(), 'offsite' => false]));
        $this->assertSame('warn', $this->checkStatus('backup'));

        \App\Support\Settings::set('backup_last', json_encode(['at' => now()->toIso8601String(), 'offsite' => true]));
        $this->assertSame('ok', $this->checkStatus('backup'));

        $this->travel(2)->days();
        $this->assertSame('fail', $this->checkStatus('backup'));
    }

    public function test_a_job_stuck_in_the_queue_fails(): void
    {
        config(['queue.default' => 'database']);
        \DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);

        $this->assertSame('fail', $this->checkStatus('queue'));
    }

    public function test_production_settings_are_checked(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true, 'session.encrypt' => false]);

        $this->assertSame('fail', $this->checkStatus('security'));
    }

    public function test_health_answers_a_monitor_with_statuses_only_and_503_on_failure(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.scheduler', 'fail');
        $this->assertStringNotContainsString('BACKUP_ENCRYPTION_KEY', $response->getContent(), 'no details for the public');

        cache()->forget('health:public');
        ScheduledRun::tick();
        config(['backup.encryption_key' => \App\Support\Backups\BackupCipher::newKey()]);
        \App\Support\Settings::set('backup_last', json_encode(['at' => now()->toIso8601String(), 'offsite' => true]));
        $this->getJson('/health')->assertOk()->assertJsonPath('checks.database', 'ok');
    }

    public function test_td_check_prints_the_table_and_fails_when_something_fails(): void
    {
        $this->artisan('td:check')->expectsOutputToContain('scheduler')->assertFailed();

        ScheduledRun::tick();
        config(['backup.encryption_key' => \App\Support\Backups\BackupCipher::newKey()]);
        \App\Support\Settings::set('backup_last', json_encode(['at' => now()->toIso8601String(), 'offsite' => true]));
        $this->artisan('td:check --json')->expectsOutputToContain('"status"')->assertSuccessful();
    }
}
