<?php

namespace App\Observers;

use App\Support\CurrentHospital;
use App\Support\SyncRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stamps a revision on every write to a pullable table.
 *
 * Without this, a patient edited in the admin panel gets no `sync_revision`
 * and **no device ever pulls it** — offline users would silently drift out of
 * date about everything the online staff did, which is a worse failure than
 * being offline in the first place, because nothing on screen says so.
 *
 * ── Why an observer, when this codebase bans model save-hooks ─────────────
 * `decisions.md` records that visit state and BMI go through services, never
 * Eloquent hooks, because the legacy system computed billing as a save side
 * effect and that was its top bug source. That rule is about BUSINESS LOGIC.
 * A revision counter is infrastructure, in the same family as `updated_at`:
 * it carries no rule, changes no meaning, and must catch every write path
 * including the twenty-eight services and the raw ones. The alternative is
 * remembering to call it in every writer, which is the same as not having it.
 *
 * `saving`, not `saved`: setting the attribute before the write makes it part
 * of the same UPDATE. Doing it afterwards would be a second write, and would
 * either recurse or need `saveQuietly`, which is how a "quiet" write ends up
 * skipping the next observer somebody adds.
 */
class SyncRevisionObserver
{
    public function saving(Model $model): void
    {
        // Only when something a device would notice actually changed. A touch
        // that moves nothing should not burn a revision and wake every device.
        if ($model->exists && ! $model->isDirty()) {
            return;
        }

        $hospitalId = $this->hospitalFor($model);

        if ($hospitalId === null) {
            // No tenant to count for — a console or seeder context with no
            // resolved hospital. Nothing written there is pullable by a device.
            return;
        }

        $model->setAttribute('sync_revision', SyncRevision::next($hospitalId));
    }

    /**
     * A soft delete is a change a device must learn about (plan §10.2).
     *
     * Written with the query builder rather than by setting an attribute,
     * because `runSoftDelete()` builds its own UPDATE containing only the
     * deleted-at and timestamp columns — anything set on the model here is
     * simply dropped. Done AFTER the delete so the two are one logical change
     * and a reader cannot catch a revision pointing at a row that is not yet
     * marked gone.
     */
    public function deleted(Model $model): void
    {
        if (! method_exists($model, 'trashed') || ! $model->trashed()) {
            return;
        }

        $hospitalId = $this->hospitalFor($model);

        if ($hospitalId === null) {
            return;
        }

        DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->update(['sync_revision' => SyncRevision::next($hospitalId)]);
    }

    /**
     * Which hospital's counter to draw from.
     *
     * The column first, then the resolved tenant — because on a CREATE the
     * column is not filled yet. `saving` fires before `creating`, and
     * `BelongsToHospital` fills `hospital_id` on `creating`, so reading the
     * column alone meant every newly created record got no revision at all and
     * no device ever pulled it. Falling back to `CurrentHospital` is not a
     * guess: it is the same source the trait itself uses a moment later.
     */
    private function hospitalFor(Model $model): ?int
    {
        $onRow = $model->getAttribute('hospital_id');

        if ($onRow !== null) {
            return (int) $onRow;
        }

        return app(CurrentHospital::class)->id();
    }
}
