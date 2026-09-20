<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property SubscriptionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $trial_ends_at
 */
class Subscription extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'hospital_id', 'plan_id', 'starts_at', 'ends_at', 'trial_ends_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'status' => SubscriptionStatus::class,
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Grants tenant access right now: the right status AND not past its end date. */
    public function isCurrentlyActive(): bool
    {
        if (! $this->status->grantsAccess()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
