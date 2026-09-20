<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a manual/mobile-money subscription payment
 * (HMS_PLAN.md §2.2 — "start manual/mobile-money-friendly: record payment
 * → extend"; the Flutterwave gateway adapter is Phase 4 §16). `method` is a
 * plain string, not an enum, deliberately — with exactly one value today
 * ("manual") an enum would be speculative; it becomes one once Phase 4
 * introduces real gateway methods.
 */
class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'amount', 'method', 'reference', 'notes', 'recorded_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
