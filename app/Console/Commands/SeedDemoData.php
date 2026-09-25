<?php

namespace App\Console\Commands;

use Database\Seeders\DemoPopulationSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Fill the demonstration hospital, without rebuilding the database.
 *
 * `migrate:fresh --seed` does this too, and throws away everything else in the
 * process. This is for the ordinary case: an existing dev database that wants
 * the demo data topped up because the seeder gained a scenario.
 *
 * Both seeders are idempotent, so running it twice is a no-op rather than a
 * second hospital.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--accounts-only : Just the per-role test accounts, not the three months of work}';

    protected $description = 'Seed (or top up) the demonstration hospital and its test accounts';

    public function handle(): int
    {
        if (! app()->environment(['local', 'demo']) && ! config('demo.enabled')) {
            $this->error('demo:seed needs either a local environment or DEMO_MODE=true — these are known-password accounts.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        if (! $this->option('accounts-only')) {
            $this->call('db:seed', ['--class' => DemoPopulationSeeder::class, '--force' => true]);
        }

        $this->newLine();
        $this->info('Sign in at '.route('test-login').' — password '.DemoSeeder::PASSWORD);

        return self::SUCCESS;
    }
}
