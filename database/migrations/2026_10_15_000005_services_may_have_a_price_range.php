<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price list line can be a range — "Difficult extraction 50,000 – 80,000" —
 * and the counter charges within it. `price` stays the starting price;
 * `max_price` is the top of the range, null for a fixed price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('max_price', 12, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('max_price');
        });
    }
};
