<?php

use App\Support\SyncRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every row that already exists a revision.
 *
 * A device asks "what has changed since revision N?" and starts at zero. Rows
 * written before offline sync existed have `sync_revision = null`, so they
 * match no revision comparison and **no device would ever receive them** — a
 * hospital would enable offline mode and find its patient list empty.
 *
 * Assigned in id order, per hospital, so the numbering is deterministic and a
 * first sync delivers the oldest record first. Done in chunks because a busy
 * hospital's `patients` table is not something to load into memory, and with
 * `SyncRevision::next()` rather than a hand-rolled counter so the per-hospital
 * sequence row ends up at the right place for the writes that follow.
 */
return new class extends Migration
{
    /** Ordered so a parent is numbered before its children. */
    private const TABLES = [
        'patients', 'visits', 'admissions',
        'vital_rounds', 'nursing_notes', 'medication_administrations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sync_revision')) {
                continue;
            }

            $hospitals = DB::table($table)->select('hospital_id')->distinct()->pluck('hospital_id');

            foreach ($hospitals as $hospitalId) {
                if ($hospitalId === null) {
                    continue;
                }

                DB::table($table)
                    ->where('hospital_id', $hospitalId)
                    ->whereNull('sync_revision')
                    ->orderBy('id')
                    ->select('id')
                    ->chunkById(500, function ($rows) use ($table, $hospitalId) {
                        foreach ($rows as $row) {
                            DB::table($table)
                                ->where('id', $row->id)
                                ->update(['sync_revision' => SyncRevision::next((int) $hospitalId)]);
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Clearing the revisions would strand every
        // device's cursor past rows it would then never be sent again, which
        // is the exact failure this migration exists to prevent.
    }
};
