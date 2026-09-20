<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Priced per month in UGX platform-wide (PlanSeeder) — the currency Pesapal
 * settles in, so the price quoted on the subscription page is exactly the
 * amount charged, with no conversion anywhere on the money path. A USD figure
 * is shown beside it for reference only (App\Support\PlatformCurrency). The
 * plan row itself stores no currency: there is only one.
 *
 * @property \App\Enums\BillingCycle $billing_cycle
 * @property numeric-string $price
 * @property array<string,mixed>|null $limits
 * @property list<string>|null $features
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'slug', 'price', 'billing_cycle', 'limits', 'features', 'is_active', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_cycle' => BillingCycle::class,
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
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
        static::creating(function (self $plan) {
            $plan->slug ??= self::uniqueSlug($plan->name);
        });
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function limit(string $key, mixed $default = null): mixed
    {
        return data_get($this->limits, $key, $default);
    }

    /**
     * The monthly price, as the public pages print it.
     *
     * Here rather than in each view so the pricing page and the sign-up form
     * cannot quote the same plan differently — which is the one disagreement
     * on a pricing page nobody forgives. Plan prices are platform-level, not
     * tenant-level, so this deliberately does NOT go through HospitalSettings:
     * a visitor has no hospital, and the price is the same whoever is reading.
     */
    public function priceLabel(): string
    {
        return '$'.number_format((float) $this->price, 0);
    }
}
