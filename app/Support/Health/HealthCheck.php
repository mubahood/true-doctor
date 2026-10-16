<?php

namespace App\Support\Health;

use App\Services\Backups\OffsiteBackup;
use App\Services\Backups\RestoreDrill;
use App\Services\Install\InstallationBackups;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\Scheduling\ScheduledRun;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is everything that keeps a hospital running actually running? One list of
 * checks, read by `td:check` (for a person) and `/health` (for a monitor):
 * the database, migrations, the queue, the scheduler, backups and the
 * restore drill, disk space, mail, the payment gateway, the licence, and the
 * settings that must be right in production.
 *
 * Each check is `ok`, `warn` (somebody should look this week) or `fail`
 * (somebody should look now). A failure of one check never stops the others.
 */
final class HealthCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** @return list<array{key:string, status:string, message:string}> */
    public function run(): array
    {
        $checks = [];
        foreach ([
            'database' => fn () => $this->database(),
            'migrations' => fn () => $this->migrations(),
            'queue' => fn () => $this->queue(),
            'failed_jobs' => fn () => $this->failedJobs(),
            'scheduler' => fn () => $this->scheduler(),
            'scheduled_jobs' => fn () => $this->scheduledJobs(),
            'backup' => fn () => $this->backup(),
            'restore_drill' => fn () => $this->drill(),
            'disk' => fn () => $this->disk(),
            'storage' => fn () => $this->storage(),
            'mail' => fn () => $this->mail(),
            'payments' => fn () => $this->payments(),
            'licence' => fn () => $this->licence(),
            'security' => fn () => $this->security(),
        ] as $key => $check) {
            try {
                [$status, $message] = $check();
            } catch (\Throwable $e) {
                [$status, $message] = [self::FAIL, 'The check itself failed: '.$e->getMessage()];
            }
            if ($status !== null) {
                $checks[] = ['key' => $key, 'status' => $status, 'message' => $message];
            }
        }

        return $checks;
    }

    /** @param list<array{status:string}> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    private function database(): array
    {
        $started = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $started) * 1000);

        return $ms > 500 ? [self::WARN, "Reachable, but slow to answer ({$ms} ms)."] : [self::OK, "Reachable ({$ms} ms)."];
    }

    private function migrations(): array
    {
        $migrator = app(Migrator::class);
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

        return $pending === [] ? [self::OK, 'All migrations have run.'] : [self::FAIL, count($pending).' migrations have not run.'];
    }

    private function queue(): array
    {
        if (config('queue.default') === 'sync') {
            return [self::OK, 'Jobs run as they are made (sync queue).'];
        }
        if (config('queue.default') !== 'database') {
            $size = \Illuminate\Support\Facades\Queue::size();

            return $size > 200 ? [self::WARN, "{$size} jobs waiting on ".config('queue.default').': is a worker running?'] : [self::OK, "{$size} jobs waiting on ".config('queue.default').'.'];
        }
        $table = (string) config('queue.connections.database.table', 'jobs');
        $stuck = DB::table($table)->where('available_at', '<', now()->subMinutes(10)->getTimestamp())->whereNull('reserved_at')->count();
        $waiting = DB::table($table)->count();

        return $stuck > 0
            ? [self::FAIL, "{$stuck} queued jobs have waited more than 10 minutes: the worker is not draining the queue (emails and reminders are not going out)."]
            : [self::OK, "{$waiting} jobs waiting, none for long."];
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [null, ''];
        }
        $recent = DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count();

        return $recent > 0 ? [self::WARN, "{$recent} jobs failed in the last 24 hours (see failed_jobs)."] : [self::OK, 'No job failed in the last 24 hours.'];
    }

    private function scheduler(): array
    {
        $last = ScheduledRun::lastTick();
        if ($last === null) {
            return [self::FAIL, 'The scheduler has never run: add the cron job (`php artisan schedule:run` every minute).'];
        }
        $minutes = (int) $last->diffInMinutes(now());
        if ($minutes > 60) {
            return [self::FAIL, "The scheduler last ran {$minutes} minutes ago: the cron job has stopped."];
        }
        if ($minutes > 5) {
            return [self::WARN, "The scheduler last ran {$minutes} minutes ago. Run the cron every minute so reminders and the queue keep up."];
        }

        return [self::OK, 'The scheduler ran '.($minutes === 0 ? 'this minute' : "{$minutes} minutes ago").'.'];
    }

    private function scheduledJobs(): array
    {
        $failing = array_filter(ScheduledRun::all(), fn ($r) => (int) $r->failures_in_a_row >= 3);

        return $failing === []
            ? [self::OK, 'No scheduled job is failing.']
            : [self::WARN, 'Failing again and again: '.implode(', ', array_map(fn ($r) => $r->name, $failing)).'.'];
    }

    private function backup(): array
    {
        if (Edition::isStandalone()) {
            $age = app(InstallationBackups::class)->newestAge();
            if ($age === null) {
                return [self::FAIL, 'There is no backup on this server yet.'];
            }

            return $age > 36 * 3600 ? [self::WARN, 'The newest backup is '.intdiv($age, 3600).' hours old.'] : [self::OK, 'Backed up '.intdiv($age, 3600).' hours ago.'];
        }

        if (blank(config('backup.encryption_key'))) {
            return [self::FAIL, 'Backups are not set up: BACKUP_ENCRYPTION_KEY is missing.'];
        }
        $last = OffsiteBackup::last();
        if ($last === null) {
            return [self::FAIL, 'No backup has been made yet.'];
        }
        $hours = (int) Carbon::parse($last['at'])->diffInHours(now());
        if ($hours > 36) {
            return [self::FAIL, "The last backup was {$hours} hours ago."];
        }
        if (empty($last['offsite'])) {
            return [self::WARN, "Backed up {$hours} hours ago, but only on this server: set BACKUP_S3_* to send it off the server."];
        }

        return [self::OK, "Backed up off the server {$hours} hours ago."];
    }

    private function drill(): array
    {
        if (Edition::isStandalone()) {
            return [null, ''];
        }
        $last = RestoreDrill::last();
        if ($last === null) {
            return [self::WARN, 'No backup has been test-restored yet (td:backup-drill).'];
        }
        $days = (int) Carbon::parse($last['at'])->diffInDays(now());
        if (empty($last['ok'])) {
            return [self::FAIL, 'The last restore drill failed: '.implode(' ', (array) ($last['problems'] ?? []))];
        }
        if ($days > 40) {
            return [self::WARN, "The last restore drill was {$days} days ago."];
        }

        return [($last['mode'] ?? '') === 'restore' ? self::OK : self::WARN, 'The newest backup was '.(($last['mode'] ?? '') === 'restore' ? 'restored and checked' : 'read (no scratch database is set, so not restored)')." {$days} days ago."];
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return [self::WARN, 'Free disk space could not be read.'];
        }
        $gb = round($free / 1073741824, 1);
        if ($free < 200 * 1048576) {
            return [self::FAIL, "Only {$gb} GB free: uploads and backups will start failing."];
        }

        return $free < 1073741824 ? [self::WARN, "{$gb} GB free."] : [self::OK, "{$gb} GB free."];
    }

    private function storage(): array
    {
        foreach ([storage_path('framework/views'), storage_path('framework/cache'), storage_path('app/private'), base_path('bootstrap/cache')] as $folder) {
            if (! is_dir($folder) || ! is_writable($folder)) {
                return [self::FAIL, basename(dirname($folder)).'/'.basename($folder).' is not writable.'];
            }
        }

        return [self::OK, 'Storage folders are writable.'];
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        if (app()->isProduction() && in_array($mailer, ['log', 'array'], true)) {
            return [self::FAIL, "Mail goes to the {$mailer} driver: nobody receives it."];
        }
        if (str_starts_with(strtolower($from), 'noreply@') || str_starts_with(strtolower($from), 'no-reply@')) {
            return [self::WARN, "Mail is sent from {$from}: replies are lost. Use a real address."];
        }

        return [self::OK, "Mail: {$mailer}, from {$from}."];
    }

    private function payments(): array
    {
        if (! Edition::isSaas() || ! app()->isProduction()) {
            return [null, ''];
        }
        if (filled(config('services.pesapal.consumer_key')) && config('services.pesapal.environment') !== 'production') {
            return [self::WARN, 'Pesapal is in sandbox mode: real payments will not go through.'];
        }

        return [self::OK, 'Payment gateway mode is live (or not set up).'];
    }

    private function licence(): array
    {
        if (! Edition::isStandalone()) {
            return [null, ''];
        }

        return app(InstalledLicence::class)->isValid() ? [self::OK, 'The licence is valid.'] : [self::WARN, 'The licence is missing or invalid: updates are unavailable (care continues).'];
    }

    private function security(): array
    {
        if (! app()->isProduction()) {
            return [self::OK, 'Not production: production settings not checked.'];
        }
        $problems = [];
        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG is on (errors show code to visitors)';
        }
        if (! config('session.encrypt')) {
            $problems[] = 'sessions are not encrypted';
        }
        if (str_starts_with((string) config('app.url'), 'https://') && ! config('session.secure')) {
            $problems[] = 'the session cookie is not marked secure';
        }

        return $problems === [] ? [self::OK, 'Production settings are safe.'] : [self::FAIL, ucfirst(implode('; ', $problems)).'.'];
    }
}
