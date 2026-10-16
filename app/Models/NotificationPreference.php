<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person wants to hear about. A missing row means "the default for
 * this person's role": a category they have no permission to act on starts
 * off; everything else starts on.
 */
class NotificationPreference extends Model
{
    public const CATEGORIES = ['clinical', 'ward', 'schedule', 'stock', 'billing', 'sync', 'account', 'system'];

    public const PRIORITIES = ['low', 'normal', 'urgent'];

    public const DEFAULT_QUIET_START = '22:00';

    public const DEFAULT_QUIET_END = '06:00';

    protected $fillable = ['user_id', 'category', 'enabled', 'channels', 'quiet_start', 'quiet_end', 'tz'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'channels' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The permission that makes a category worth hearing about; null = everyone. */
    private const NEEDS = [
        'clinical' => 'visits.view',
        'ward' => 'ipd.view',
        'schedule' => 'appointments.view',
        'stock' => 'pharmacy.view',
        'billing' => 'billing.view',
        'sync' => null,
        'account' => null,
        'system' => null,
    ];

    /** @return array<string,bool> category => on by default for this person */
    public static function defaultsFor(object $user): array
    {
        $out = [];
        foreach (self::NEEDS as $category => $permission) {
            $out[$category] = $permission === null || (method_exists($user, 'can') && $user->can($permission));
        }

        return $out;
    }

    /** @return array<string,self> keyed by category */
    public static function savedFor(object $user): array
    {
        return self::where('user_id', $user->id)->get()->keyBy('category')->all();
    }

    public static function allows(object $user, string $category): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return true;
        }
        $saved = self::where('user_id', $user->id)->where('category', $category)->first();

        return $saved !== null ? $saved->enabled : (self::defaultsFor($user)[$category] ?? true);
    }
}
