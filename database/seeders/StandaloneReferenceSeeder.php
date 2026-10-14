<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Reference data only: no SaaS plans, central account, trial or demo tenant. */
class StandaloneReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RbacSeeder::class, DistrictSeeder::class]);
    }
}
