<?php

namespace Tests\Unit\Fixtures;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal tenant-scoped model, backed by a scratch table created for the
 * duration of BelongsToHospitalTest. No real tenant model exists yet
 * (Patient et al. land in Phase 1) — this proves the BelongsToHospital
 * mechanism itself in isolation, per HMS_PLAN.md §7 Phase 0 Step 3
 * ("first tenancy-isolation tests").
 */
class TenancyScopeProbe extends Model
{
    use BelongsToHospital;

    protected $table = 'tenancy_scope_probes';

    protected $fillable = ['hospital_id', 'name'];
}
