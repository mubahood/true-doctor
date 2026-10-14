<?php

namespace App\Jobs;

use App\Models\StandaloneRelease;
use App\Services\Releases\CleanInstallProof;
use App\Services\Releases\StageStandaloneRelease;
use App\Support\Edition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildStandaloneRelease implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly int $releaseId) {}

    public function handle(StageStandaloneRelease $builder, CleanInstallProof $proof): void
    {
        if (! Edition::isSaas()) {
            throw new \RuntimeException('A customer installation cannot build releases.');
        }
        $release = StandaloneRelease::findOrFail($this->releaseId);
        if ($release->status !== 'queued') {
            return;
        }
        $release->update(['status' => 'building', 'progress' => 'Starting']);
        try {
            $source = (string) config('edition.release_source');
            $destination = storage_path('app/private/releases/'.$release->version.'.zip');
            $built = $builder->run($release->version, $source, $destination, function (string $message) use ($release): void {
                $release->update(['progress' => $message]);
            });
            $proof->run($built['path'], function (string $message) use ($release): void {
                $release->update(['progress' => $message]);
            });
            $release->update([
                'status' => 'staged', 'progress' => 'Ready to publish',
                'commit' => $built['commit'], 'archive_path' => $built['path'], 'sha256' => $built['sha256'],
                'file_count' => $built['count'], 'staged_at' => now(), 'clean_install_passed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $release->update(['status' => 'failed', 'progress' => 'Build failed', 'failure' => mb_substr($exception->getMessage(), 0, 4000)]);
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        StandaloneRelease::whereKey($this->releaseId)->whereIn('status', ['queued', 'building'])
            ->update(['status' => 'failed', 'progress' => 'Release worker failed', 'failure' => mb_substr($exception->getMessage(), 0, 4000)]);
    }
}
