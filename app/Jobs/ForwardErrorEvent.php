<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends one (already scrubbed) error to Sentry or a self-hosted GlitchTip,
 * which speak the same store API. Queued, so a slow tracker never slows the
 * page that failed. The payload has no names, phones or record content: the
 * user and hospital go as ids only.
 */
class ForwardErrorEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    /** @param array<string,mixed> $event */
    public function __construct(public array $event) {}

    public function handle(): void
    {
        $dsn = parse_url((string) config('services.error_tracking.dsn'));
        if (! is_array($dsn) || empty($dsn['host']) || empty($dsn['user']) || empty($dsn['path'])) {
            return;
        }
        $project = trim((string) $dsn['path'], '/');
        $base = ($dsn['scheme'] ?? 'https').'://'.$dsn['host'].(isset($dsn['port']) ? ':'.$dsn['port'] : '');

        $e = $this->event;
        Http::timeout(10)
            ->withHeaders(['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key='.$dsn['user'].', sentry_client=true-doctor/1.0'])
            ->post("{$base}/api/{$project}/store/", [
                'event_id' => str_replace('-', '', (string) Str::uuid()),
                'timestamp' => now()->toIso8601String(),
                'platform' => $e['source'] === 'app' ? 'other' : 'php',
                'level' => 'error',
                'release' => $e['release'] ?? null,
                'environment' => $e['environment'] ?? 'production',
                'transaction' => $e['path'] ?? null,
                'tags' => array_filter(['ref' => $e['ref'], 'source' => $e['source'], 'hospital_id' => $e['hospital_id'], 'app_version' => $e['app_version']]),
                'user' => $e['user_id'] ? ['id' => (string) $e['user_id']] : null,
                'exception' => ['values' => [[
                    'type' => $e['class'],
                    'value' => $e['message'],
                    'stacktrace' => ['frames' => array_merge((array) ($e['frames'] ?? []), $e['file'] ? [['filename' => $e['file'], 'lineno' => (int) $e['line'], 'function' => '']] : [])],
                ]]],
            ]);
    }
}
