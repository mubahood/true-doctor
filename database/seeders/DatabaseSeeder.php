<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,        // baseline role + permission matrix (Step 5 seeds the full HMS matrix)
            AdminUserSeeder::class,   // Super Admin
            DistrictSeeder::class,    // Uganda districts (kept reference data)
            PlanSeeder::class,        // public SaaS plans
            DemoSeeder::class,        // DEV-ONLY: per-role test accounts + demo data (local only)
            // DEV-ONLY: three months of work behind the demo hospital, so a
            // demonstration has something to demonstrate. Kept out of
            // DemoSeeder deliberately — SmokeRoutesTest seeds that one on
            // every run and does not need sixty visits to walk the routes.
            DemoPopulationSeeder::class,
        ]);
    }
}
