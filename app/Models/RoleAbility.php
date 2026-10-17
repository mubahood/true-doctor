<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clinic's choice about one ability for one role (Settings, Staff and
 * access): `allowed` true gives it to a role that does not have it by
 * default, false takes it from a role that does. Written only by
 * AccessService, audited (AuditTrail::MODELS).
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $role
 * @property string $ability
 * @property bool $allowed
 * @property int|null $changed_by
 */
class RoleAbility extends Model
{
    use BelongsToHospital;

    protected $fillable = ['role', 'ability', 'allowed', 'changed_by'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
