<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * How the updater runs artisan: maintenance mode in this process, and every
 * migration and check in a fresh process — on the code now on disk, not on
 * the code this process loaded before the files were swapped.
 *
 * A separate class so a test can stand in for it and never take the real
 * application down or touch its database.
 */
class ArtisanRunner
{
    public function down(): void
    {
        // --refresh: the maintenance page reloads itself through a Refresh
        // header — only maintenance responses carry it.
        Artisan::call('down', ['--render' => 'errors::503', '--retry' => 30, '--refresh' => 30]);
    }

    public function up(): void
    {
        Artisan::call('up');
    }

    /** @param list<string> $arguments */
    public function fresh(string $appRoot, array $arguments): void
    {
        $process = new Process([PHP_BINARY, $appRoot.'/artisan', ...$arguments, '--no-ansi'], $appRoot);
        $process->setTimeout(1800);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('`'.$arguments[0].'` failed: '.mb_substr(trim($process->getErrorOutput()."\n".$process->getOutput()), 0, 1500));
        }
    }
}
