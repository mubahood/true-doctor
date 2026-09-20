<?php

namespace App\Models\Scopes;

use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The single tenancy-enforcement mechanism (HMS_PLAN.md §2.1 constraint B8):
 * one key (`hospital_id`), one global scope, never manual per-query
 * filtering. Applied via the BelongsToHospital trait.
 */
class HospitalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $hospitalId = app(CurrentHospital::class)->id();

        if ($hospitalId !== null) {
            $builder->where($model->qualifyColumn('hospital_id'), $hospitalId);
        }
    }
}
