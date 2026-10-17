<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra ability given to one person on top of their role (the senior nurse
 * who also takes payments). Only ever adds; written by AccessService, audited.
 *
 * @property int $id
 * @property int $hospital_id
 * @property int $user_id
 * @property string $ability
 * @property int|null $granted_by
 */
class UserAbility extends Model
{
    use BelongsToHospital;

    protected $fillable = ['user_id', 'ability', 'granted_by'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
