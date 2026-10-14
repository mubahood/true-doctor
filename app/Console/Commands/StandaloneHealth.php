<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Support\Edition;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Route;

/**
 * Is this installation able to serve patients right now? The updater runs it
 * on the new code before it brings the site back, and a technician can run
 * it any time. Exit code 0 means yes.
 */
class StandaloneHealth extends Command
{
    protected $signature = 'standalone:health';

    protected $description = 'Check that a standalone installation can serve requests';

    public function handle(Migrator $migrator): int
    {
        $problems = [];

        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $problems[] = 'The database cannot be reached.';
        }

        if ($problems === []) {
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);
            if ($pending !== []) {
                $problems[] = count($pending).' migrations have not run.';
            }
            if (\Spatie\Permission\Models\Role::count() === 0) {
                $problems[] = 'There are no roles — the reference data is missing.';
            }
            if (Edition::isStandalone() && Hospital::withTrashed()->count() !== 1) {
                $problems[] = 'A standalone installation must have exactly one organisation.';
            }
        }

        foreach (['admin.login', 'admin.dashboard', 'admin.visits.index'] as $route) {
            if (! Route::has($route)) {
                $problems[] = "The {$route} page is missing.";
            }
        }

        foreach ([storage_path('framework/views'), storage_path('framework/cache'), base_path('bootstrap/cache')] as $folder) {
            if (! is_dir($folder) || ! is_writable($folder)) {
                $problems[] = "{$folder} is not writable.";
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info('Healthy — True-Doctor '.Edition::version().' ('.Edition::name().').');

        return self::SUCCESS;
    }
}
