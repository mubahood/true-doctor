<?php

namespace App\Models;

use App\Enums\HospitalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $currency
 * @property \App\Enums\HospitalStatus $status
 * @property array<string,mixed>|null $settings
 */
class Hospital extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'slug', 'logo', 'address', 'timezone', 'currency', 'settings', 'status',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => HospitalStatus::class,
        ];
    }

    /** Slug from the name, suffixed -2, -3… on collision (unique column). */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        for ($i = 2; static::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    protected static function booted(): void
    {
        static::creating(function (self $hospital) {
            $hospital->uuid ??= (string) Str::uuid();
            $hospital->slug ??= self::uniqueSlug($hospital->name);
        });
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', ['trialing', 'active'])
            ->latest('starts_at')
            ->first();
    }
}
