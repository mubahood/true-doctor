<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a movement was worth, at the moment it happened.
 *
 * The ledger recorded `unit_cost`, and only on the way IN. So the two questions
 * a store is actually judged on — what did this cost us, and what did we sell
 * it for — could not be answered from it: an item repriced last week made every
 * dispensation before it look like it had been sold at today's price.
 *
 * Both prices are now stamped on every row as they stood at the time. Old rows
 * are deliberately NOT backfilled: the honest answer for a movement that
 * predates this is "not recorded", and a report quietly filled in with today's
 * prices is worse than one that says what it does not know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'unit_price')) {
                // What one unit was being SOLD for when this moved.
                $table->decimal('unit_price', 12, 2)->nullable()->after('unit_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });
    }
};
