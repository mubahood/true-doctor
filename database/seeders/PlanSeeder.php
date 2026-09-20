<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The public SaaS plans a hospital can subscribe to (HMS_PLAN.md §21). Plans are
 * global (not tenant-scoped); prices are monthly, in the platform currency
 * (UGX — what Pesapal settles in, so the quote is the charge). A missing limit
 * key means "unlimited" — see App\Support\PlanLimit.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter', 'price' => '10000.00',
                'description' => 'For a single clinic finding its feet',
                'limits' => ['max_staff' => 5, 'max_patients' => 100, 'max_beds' => 10],
                'features' => ['Up to 5 staff accounts', 'Up to 100 patients', 'Up to 10 beds', 'Email support'],
                'is_featured' => false,
            ],
            [
                'name' => 'Professional', 'slug' => 'professional', 'price' => '50000.00',
                'description' => 'For a growing hospital with several departments',
                'limits' => ['max_staff' => 50, 'max_patients' => 1000, 'max_beds' => 100],
                'features' => ['Up to 50 staff accounts', 'Up to 1,000 patients', 'Up to 100 beds', 'Email support'],
                'is_featured' => true,
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => '100000.00',
                'description' => 'For a multi-branch hospital group',
                'limits' => [], // unlimited
                'features' => ['Unlimited staff accounts', 'Unlimited patients', 'Unlimited beds', 'Email support'],
                'is_featured' => false,
            ],
        ];

        foreach ($plans as $p) {
            Plan::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'], 'description' => $p['description'], 'price' => $p['price'],
                    'billing_cycle' => 'monthly', 'limits' => $p['limits'], 'features' => $p['features'],
                    'is_active' => true, 'is_featured' => $p['is_featured'],
                ],
            );
        }

        $this->command->info('PlanSeeder: '.count($plans).' plans seeded.');
    }
}
