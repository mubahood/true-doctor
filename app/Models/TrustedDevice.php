<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that passed two-step sign-in and was asked to be remembered. The
 * cookie holds a random token; only its SHA-256 is stored here, so a copy of
 * this table opens nothing. Per person, like their API tokens: not a tenant
 * record, and gone with the person.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'ip', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
