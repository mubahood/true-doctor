<?php

namespace App\Observers;

use App\Support\CurrentHospital;
use App\Support\ReferenceRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Moves a reference section's revision when a model that feeds it changes.
 *
 * Same family as {@see SyncRevisionObserver}: infrastructure, no business rule.
 * `created`, `updated`, `deleted` and `restored` run after the write, so a reader that sees
 * the new revision also sees the new row. A created row always counts; an update
 * counts only when a column the section actually shows changed (see
 * {@see ReferenceRevision::WATCH}) - a stock balance or a bed's `updated_at`
 * must not wake every phone.
 */
class ReferenceRevisionObserver
{
    public function created(Model $model): void
    {
        $this->bumpAll($model);
    }

    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        foreach (ReferenceRevision::sectionsFor($model::class) as $section) {
            $watched = ReferenceRevision::columnsFor($section, $model::class);

            if ($watched === null || array_intersect($watched, $changed) !== []) {
                ReferenceRevision::bump($section, $this->hospitalFor($model));
            }
        }
    }

    public function deleted(Model $model): void
    {
        $this->bumpAll($model);
    }

    public function restored(Model $model): void
    {
        $this->bumpAll($model);
    }

    private function bumpAll(Model $model): void
    {
        foreach (ReferenceRevision::sectionsFor($model::class) as $section) {
            ReferenceRevision::bump($section, $this->hospitalFor($model));
        }
    }

    private function hospitalFor(Model $model): ?int
    {
        $onRow = $model->getAttribute('hospital_id');

        return $onRow !== null ? (int) $onRow : app(CurrentHospital::class)->id();
    }
}
