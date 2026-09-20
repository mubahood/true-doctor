<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Support\PlatformPrice;
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
     * The monthly price, as a public page prints it.
     *
     * Plan prices are stored in shillings — that is what a subscription is
     * settled in. This quotes them in shillings to a reader in East Africa
     * and converts for everyone else, at the one platform rate that the
     * subscription checkout also charges against.
     *
     * It used to be `'$'.number_format($this->price)`, which put a dollar
     * sign in front of a shilling figure and advertised the Starter plan at
     * ten thousand dollars a month on the sign-up form while the pricing
     * page beside it said $2.63.
     *
     * Deliberately NOT HospitalSettings: that formats a hospital's own
     * billing currency, which is what it charges its patients. What we
     * charge the hospital is a different number in a different currency.
     *
     * @param  string|null  $currency  null resolves from where the reader is
     */
    public function priceLabel(?string $currency = null): string
    {
        return PlatformPrice::make($this->price, $currency)->label();
    }
}
