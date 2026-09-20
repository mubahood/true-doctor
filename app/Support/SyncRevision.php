<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A per-hospital monotonic counter stamped on every syncable write.
 *
 * The pull cursor is a revision, not a timestamp. That is not a style
 * preference: `updated_at` collides at second resolution, and rows written
 * inside one transaction can carry timestamps that straddle another reader's
 * cursor — so a change is skipped and, because the cursor has moved past it,
 * never sent again. Silent, permanent, and invisible until somebody notices a
 * record that never reached a device.
 *
 * Same shape as `Support\Sequence`, and for the same reason: handed out under
 * a row lock so two concurrent writers cannot get the same value.
 */
final class SyncRevision
{
    /** The next revision for this hospital. */
    public static function next(?int $hospitalId = null): int
    {
        $hospitalId ??= app(CurrentHospital::class)->id();

        if ($hospitalId === null) {
            // No tenant resolved — a console or queue context. Nothing being
            // written there is pullable by a device, so there is no revision
            // to hand out and pretending otherwise would burn numbers.
            return 0;
        }

        $run = function () use ($hospitalId): int {
            $base = DB::table('sync_revisions')->where('hospital_id', $hospitalId);

            $row = (clone $base)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('sync_revisions')->insertOrIgnore([
                    'hospital_id' => $hospitalId,
                    'next_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = (clone $base)->lockForUpdate()->first();
            }

            $value = (int) $row->next_value + 1;
            DB::table('sync_revisions')->where('id', $row->id)->update([
                'next_value' => $value,
                'updated_at' => now(),
            ]);

            return $value;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    /** Where this hospital's counter stands, without advancing it. */
    public static function current(?int $hospitalId = null): int
    {
        $hospitalId ??= app(CurrentHospital::class)->id();

        if ($hospitalId === null) {
            return 0;
        }

        return (int) (DB::table('sync_revisions')->where('hospital_id', $hospitalId)->value('next_value') ?? 0);
    }
}
