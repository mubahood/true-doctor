<?php

namespace App\Support;

use App\Enums\LabOrderStatus;
use App\Models\LabOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The lab bench's reading of its own work: what is outstanding, how old the
 * oldest of it is, and which orders a search or filter means. Shared by the
 * web worklist and the app, so both count the same things.
 */
final class LabBench
{
    /** Waiting longer than this is the thing a bench needs to see. */
    public const OLD_HOURS = 24;

    /** @return list<string> the statuses that still owe a result */
    public static function outstandingStatuses(): array
    {
        return [
            LabOrderStatus::Ordered->value,
            LabOrderStatus::Collected->value,
            LabOrderStatus::Processing->value,
        ];
    }

    /**
     * What the bench owes back, and how old the oldest of it is. A count of
     * orders is not a workload; "four collected, the oldest waiting two days"
     * is.
     *
     * @return array{ordered:int,collected:int,processing:int,completed:int,oldest:int|null}
     */
    public static function tally(): array
    {
        $counts = LabOrder::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldest = LabOrder::query()
            ->whereIn('status', self::outstandingStatuses())
            ->min('created_at');

        return [
            'ordered' => (int) ($counts[LabOrderStatus::Ordered->value] ?? 0),
            'collected' => (int) ($counts[LabOrderStatus::Collected->value] ?? 0),
            'processing' => (int) ($counts[LabOrderStatus::Processing->value] ?? 0),
            'completed' => (int) LabOrder::whereDate('completed_at', now()->toDateString())->count(),
            'oldest' => $oldest === null ? null : (int) Carbon::parse($oldest)->diffInHours(now()),
        ];
    }

    /** How long this order has been on the bench, in hours. */
    public static function waitedHours(LabOrder $order): int
    {
        return (int) $order->created_at->diffInHours(now());
    }

    public static function isOverdue(LabOrder $order): bool
    {
        return ! $order->status->isTerminal() && self::waitedHours($order) >= self::OLD_HOURS;
    }

    /**
     * The worklist's filters: a status, only what is outstanding, and the
     * patient's name or number.
     *
     * @param  Builder<LabOrder>  $query
     * @return Builder<LabOrder>
     */
    public static function filter(Builder $query, ?string $status, bool $outstanding, ?string $search): Builder
    {
        $search = trim((string) $search);

        return $query
            ->when(($status ?? '') !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($outstanding, fn (Builder $q) => $q->whereIn('status', self::outstandingStatuses()))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('patient_no', 'like', "%{$search}%")));
    }
}
