<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vitals, nursing notes and medication administrations get a `uuid`.
 *
 * Twenty tables in this system already carry one; these three do not, because
 * nothing ever needed to name a bedside record from outside the server. Offline
 * capture does: the device mints the id at the bedside and the record keeps it
 * for life (invariant I-8).
 *
 * The id is also the second net under the idempotency ledger. A device told
 * "accepted" that loses the reply and then re-queues the work under a NEW
 * operation id slips past the ledger — different operation, same record — and
 * the unique uuid is what catches it. That is not a theoretical path: it is
 * what happens when a browser is killed between receiving a response and
 * committing the result locally.
 *
 * Nullable, because every row already in these tables was written online and
 * has no device id to claim. Unique, because MySQL permits many NULLs in a
 * unique index and the constraint only has to bind the rows that have one.
 */
return new class extends Migration
{
    private const TABLES = ['vital_rounds', 'nursing_notes', 'medication_administrations'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            // Index first: SQLite refuses to drop a column an index still
            // names, and this migration is rolled back and forward in tests.
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                $t->dropColumn('uuid');
            });
        }
    }
};
