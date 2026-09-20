<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HMS_PLAN.md §2.2: manual/mobile-money-friendly subscription billing —
 * "record payment → extend". Recording a payment and extending/reactivating
 * the subscription must commit or roll back together (constraint F).
 */
class SubscriptionService
{
    /** @param array{amount: numeric-string|float, method: string, reference: ?string, notes: ?string, paid_at: string, extend_days: int} $data */
    public function recordPayment(Subscription $subscription, array $data, User $recordedBy): SubscriptionPayment
    {
        return DB::transaction(function () use ($subscription, $data, $recordedBy) {
            $payment = $subscription->payments()->create([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $recordedBy->id,
                'paid_at' => $data['paid_at'],
            ]);

            $extendDays = (int) $data['extend_days'];
            if ($extendDays > 0) {
                $base = ($subscription->ends_at && $subscription->ends_at->isFuture())
                    ? $subscription->ends_at
                    : now();
                $subscription->ends_at = $base->copy()->addDays($extendDays);
            }

            if (! $subscription->status->grantsAccess()) {
                $subscription->status = SubscriptionStatus::Active;
            }

            $subscription->save();

            return $payment;
        });
    }
}
