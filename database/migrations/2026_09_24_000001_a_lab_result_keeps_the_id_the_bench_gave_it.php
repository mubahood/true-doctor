<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lab_order_items` gets a `uuid`.
 *
 * A result is filled in on a device against a line the SERVER created, so
 * unlike a vitals round there is no risk of the device inventing the record.
 * The uuid is still needed, for one reason: it is the only identifier the two
 * sides can agree on without the device having to hold server ids, and every
 * other syncable entity in this system is reconciled by uuid. One exception
 * would mean one code path that works differently for no reason anybody could
 * remember in six months.
 *
 * Backfilled, not nullable-and-ignored: existing rows will be pulled down to
 * devices, and a row with no uuid is a row a device cannot report a result
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_order_items') || Schema::hasColumn('lab_order_items', 'uuid')) {
            return;
        }

        Schema::table('lab_order_items', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
        });

        \Illuminate\Support\Facades\DB::table('lab_order_items')
            ->whereNull('uuid')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    \Illuminate\Support\Facades\DB::table('lab_order_items')
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lab_order_items') || ! Schema::hasColumn('lab_order_items', 'uuid')) {
            return;
        }

        // Index first: SQLite refuses to drop a column an index still names.
        Schema::table('lab_order_items', function (Blueprint $t) {
            $t->dropUnique('lab_order_items_uuid_unique');
            $t->dropColumn('uuid');
        });
    }
};
