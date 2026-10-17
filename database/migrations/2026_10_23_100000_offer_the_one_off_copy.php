<?php

use App\Models\StandaloneProduct;
use App\Support\Edition;
use Illuminate\Database\Migrations\Migration;

/*
 * The first one-off product on sale (owner's decision, 2026-10-17): one copy
 * for one organisation at UGX 1,200,000 (about USD 300), with twelve months of
 * updates, renewable at UGX 300,000 a year. Online service only; an existing
 * row with this slug is brought to these terms, nothing else is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Edition::isSaas()) {
            return;
        }

        StandaloneProduct::query()->updateOrCreate(['slug' => 'true-doctor-own-copy'], [
            'name' => 'True-Doctor, your own copy',
            'description' => 'The whole system on your own server, under your name and logo, for one organisation. Unlimited patients and staff. Twelve months of updates included. The user manual, the setup guide and installation help on WhatsApp are part of it.',
            'price_ugx' => '1200000.00',
            'renewal_price_ugx' => '300000.00',
            'updates_months' => 12,
            'limits' => null,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        // A product that may have been sold is never deleted.
    }
};
