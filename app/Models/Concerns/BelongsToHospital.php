<?php

namespace App\Models\Concerns;

use App\Models\Hospital;
use App\Models\Scopes\HospitalScope;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-scoped model (HMS_PLAN.md §2.1). Registers the
 * global scope that enforces isolation on every read, and auto-fills
 * `hospital_id` on create from the resolved tenant context — callers never
 * set it by hand, so there's no path to accidentally writing the wrong one.
 */
trait BelongsToHospital
{
    public static function bootBelongsToHospital(): void
    {
        static::addGlobalScope(new HospitalScope);

        static::creating(function ($model) {
            if ($model->hospital_id === null) {
                $model->hospital_id = app(CurrentHospital::class)->id();
            }
        });
    }

    /** @return BelongsTo<Hospital, $this> */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }
}
