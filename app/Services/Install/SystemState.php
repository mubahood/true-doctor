<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;

/**
 * What the installation's housekeeping is doing, and what it was asked to do
 * (Settings → Licence & updates).
 *
 * The web page never runs an update or a backup itself: it leaves a request,
 * and the scheduler — a command-line process with no time limit — picks it up
 * within a minute (`standalone:system-requests`). Both sides read and write
 * two small JSON files in private storage, so the page can show progress even
 * while the site is in maintenance mode for the update itself.
 */
final class SystemState
{
    /** @param array<string,mixed> $request action (update|backup), archive, by */
    public function request(array $request): void
    {
        if ($this->pending() !== null || ($this->current()['status'] ?? null) === 'running') {
            throw new \DomainException('Something is already waiting or running. Wait for it to finish.');
        }
        $this->write($this->requestPath(), $request + ['requested_at' => now()->toIso8601String()]);
        $this->write($this->statePath(), [
            'action' => $request['action'], 'status' => 'queued',
            'message' => 'Waiting for the scheduler (up to a minute)…', 'log' => [],
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function pending(): ?array
    {
        return $this->read($this->requestPath());
    }

    /** Take the waiting request, so no other worker runs it too. @return array<string,mixed>|null */
    public function claim(): ?array
    {
        // rename() is atomic: of two schedulers that overlap, one gets it.
        $claimed = $this->requestPath().'.'.getmypid().'.claimed';
        if (! is_file($this->requestPath()) || ! @rename($this->requestPath(), $claimed)) {
            return null;
        }
        $request = $this->read($claimed);
        @unlink($claimed);

        return $request;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        return $this->read($this->statePath()) ?? ['status' => 'idle'];
    }

    public function running(string $action, string $message, ?int $by = null): void
    {
        $this->write($this->statePath(), [
            'action' => $action, 'status' => 'running', 'message' => $message, 'by' => $by,
            'log' => [], 'started_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** @param list<string> $log */
    public function progress(string $message, array $log): void
    {
        $state = $this->current();
        if (($state['status'] ?? null) !== 'running') {
            $state = ['status' => 'running', 'started_at' => now()->toIso8601String()] + $state;
        }
        $this->write($this->statePath(), array_merge($state, ['message' => $message, 'log' => $log, 'updated_at' => now()->toIso8601String()]));
    }

    /** @param list<string> $log */
    public function finished(string $action, string $message, array $log = []): void
    {
        $this->write($this->statePath(), array_merge($this->current(), [
            'action' => $action, 'status' => 'succeeded', 'message' => $message, 'log' => $log,
            'finished_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]));
    }

    /** @param list<string> $log */
    public function failed(string $action, string $message, array $log = []): void
    {
        $this->write($this->statePath(), array_merge($this->current(), [
            'action' => $action, 'status' => 'failed', 'message' => $message, 'log' => $log,
            'finished_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]));
    }

    private function requestPath(): string
    {
        return storage_path('app/private/system/request.json');
    }

    private function statePath(): string
    {
        return storage_path('app/private/system/state.json');
    }

    /** @return array<string,mixed>|null */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function write(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
