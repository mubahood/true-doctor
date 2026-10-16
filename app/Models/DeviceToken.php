<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone (or browser) that has told the server how to reach it.
 *
 * @property string|null $device_uuid the install's id: the X-Device-Id the app sends
 * @property string|null $token provider token; null when the install registered without push
 * @property string|null $provider
 * @property bool $push_enabled
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id', 'device_uuid', 'token', 'platform', 'provider', 'app_version', 'locale', 'tz',
        'push_enabled', 'revoked_at', 'last_seen_at', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'push_enabled' => 'boolean',
        ];
    }

    /** Not revoked, push on, and a token to send to. @param  Builder<DeviceToken>  $q */
    public function scopeActive(Builder $q): void
    {
        $q->whereNull('revoked_at')->where('push_enabled', true)->whereNotNull('token');
    }

    /** Sign-out on a phone: it no longer gets this person's pushes. No id sent = nothing to match, nothing revoked. */
    public static function revokeFor(?User $user, ?string $deviceUuid): void
    {
        if ($user === null || $deviceUuid === null || $deviceUuid === '') {
            return;
        }
        static::where('user_id', $user->id)->where('device_uuid', $deviceUuid)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'push_enabled' => false]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
