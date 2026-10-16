<?php

namespace App\Support\Errors;

use App\Jobs\ForwardErrorEvent;
use App\Models\ErrorEvent;
use App\Support\CurrentHospital;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where errors go: grouped into `error_events` (so one failing screen is one
 * row with a count, not a thousand log lines), scrubbed of personal data,
 * and, when ERROR_TRACKING_DSN is set, forwarded to Sentry or a self-hosted
 * GlitchTip. Works on a standalone server with no outside service at all.
 *
 * Never throws: an error while recording an error is swallowed, because the
 * person in front of the screen is already having a bad enough moment.
 */
final class ErrorTracker
{
    private static bool $busy = false;

    public function capture(Throwable $e, string $source = 'web'): void
    {
        if (self::$busy || ! $this->enabled()) {
            return;
        }
        self::$busy = true;

        try {
            $ref = ErrorRef::for($e);
            $file = $this->relative($e->getFile());
            // A test's requests run "in the console" too; one with a route is a web request.
            $http = ! app()->runningInConsole() || (app()->runningUnitTests() && request()->route() !== null);
            $this->record([
                'source' => $http ? $source : (str_starts_with((string) ($_SERVER['argv'][1] ?? ''), 'queue:') ? 'queue' : ($source === 'web' ? 'console' : $source)),
                'class' => get_class($e),
                'message' => Scrubber::scrub($e->getMessage()),
                'file' => $file,
                'line' => $e->getLine(),
                'path' => $http ? '/'.ltrim(request()->path(), '/') : null,
                'user_id' => $http ? request()->user()?->getKey() : null,
                'hospital_id' => app(CurrentHospital::class)->id(),
                'app_version' => $http ? request()->header('X-App-Version') : null,
                'ref' => $ref,
                'frames' => $this->frames($e),
            ]);
        } catch (Throwable) {
            // Recording failed; the log still has it.
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Record an error the app reported (already scrubbed on the phone, and
     * scrubbed again here).
     *
     * @param  array{kind?:string, message?:string, where?:string, app_version?:string}  $report
     */
    public function captureFromApp(array $report, ?int $userId, ?int $hospitalId): string
    {
        $ref = strtoupper(\Illuminate\Support\Str::random(6));
        $this->record([
            'source' => 'app',
            'class' => mb_substr(Scrubber::scrub((string) ($report['kind'] ?? 'app')), 0, 120),
            'message' => Scrubber::scrub((string) ($report['message'] ?? '')),
            'file' => isset($report['where']) ? mb_substr(Scrubber::scrub((string) $report['where'], 250), 0, 250) : null,
            'line' => null,
            'path' => null,
            'user_id' => $userId,
            'hospital_id' => $hospitalId,
            'app_version' => isset($report['app_version']) ? mb_substr((string) $report['app_version'], 0, 32) : null,
            'ref' => $ref,
            'frames' => [],
        ]);

        return $ref;
    }

    private function enabled(): bool
    {
        try {
            return Schema::hasTable('error_events');
        } catch (Throwable) {
            return false; // no database: nowhere to record, and the log has it
        }
    }

    /** @param array<string,mixed> $e */
    private function record(array $e): void
    {
        // Grouped by where, not by the exact words (which carry ids that change).
        $shape = (string) preg_replace('/\d+/', '#', (string) $e['message']);
        $fingerprint = sha1($e['source'].'|'.$e['class'].'|'.$e['file'].'|'.$e['line'].'|'.mb_substr($shape, 0, 120));
        $now = now();

        DB::transaction(function () use ($e, $fingerprint, $now) {
            /** @var ErrorEvent|null $row */
            $row = ErrorEvent::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($row === null) {
                ErrorEvent::query()->create([
                    'fingerprint' => $fingerprint, 'source' => $e['source'], 'class' => mb_substr((string) $e['class'], 0, 191),
                    'message' => $e['message'], 'file' => $e['file'], 'line' => $e['line'], 'path' => $e['path'] !== null ? mb_substr((string) $e['path'], 0, 255) : null,
                    'last_ref' => $e['ref'], 'refs' => [$e['ref']], 'last_user_id' => $e['user_id'], 'last_hospital_id' => $e['hospital_id'],
                    'app_version' => $e['app_version'], 'release' => Edition::version(), 'occurrences' => 1,
                    'first_seen_at' => $now, 'last_seen_at' => $now,
                ]);
            } else {
                $row->forceFill([
                    'message' => $e['message'], 'path' => $e['path'] !== null ? mb_substr((string) $e['path'], 0, 255) : $row->path,
                    'last_ref' => $e['ref'], 'refs' => array_slice(array_merge([$e['ref']], (array) $row->refs), 0, 20),
                    'last_user_id' => $e['user_id'], 'last_hospital_id' => $e['hospital_id'], 'app_version' => $e['app_version'] ?? $row->app_version,
                    'release' => Edition::version(), 'occurrences' => $row->occurrences + 1, 'last_seen_at' => $now,
                    // It came back: it is not resolved after all.
                    'resolved_at' => null,
                ])->save();
            }
        });

        if (filled(config('services.error_tracking.dsn'))) {
            ForwardErrorEvent::dispatch([
                'ref' => $e['ref'], 'source' => $e['source'], 'class' => $e['class'], 'message' => $e['message'],
                'file' => $e['file'], 'line' => $e['line'], 'path' => $e['path'], 'user_id' => $e['user_id'],
                'hospital_id' => $e['hospital_id'], 'app_version' => $e['app_version'], 'frames' => $e['frames'],
                'release' => Edition::version(), 'environment' => app()->environment(),
            ]);
        }
    }

    /** @return list<array{filename:string, lineno:int, function:string}> */
    private function frames(Throwable $e): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 20) as $frame) {
            $frames[] = [
                'filename' => $this->relative((string) ($frame['file'] ?? '')),
                'lineno' => (int) ($frame['line'] ?? 0),
                'function' => (isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::') : '').$frame['function'],
            ];
        }

        return array_reverse($frames);
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(base_path(), '', $file), '/');
    }
}
